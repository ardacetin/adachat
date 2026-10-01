<?php

use App\Domain\AI\Data\InputTokenCount;
use App\Domain\AI\Data\TokenUsage;
use App\Domain\AI\Enums\InputCountMethod;
use App\Domain\Budget\Data\Settlement;
use App\Domain\Budget\Services\BudgetEngine;
use App\Domain\Institution\Settings\InstitutionSettings;
use App\Domain\Reports\UsageStatistics;
use App\Models\AiModel;
use App\Models\AuditLog;
use App\Models\BudgetPolicy;
use App\Models\Group;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    updateSettings(InstitutionSettings::class, ['timezone' => 'Europe/Istanbul']);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
    $this->admin = User::factory()->admin()->create(['locale' => 'en']);

    // $1 per million input tokens, $10 per million output tokens.
    $this->model = AiModel::factory()->create(['display_name' => 'GPT Test', 'input_price_per_million' => '1', 'output_price_per_million' => '10']);
    $policy = BudgetPolicy::factory()->create(['monthly_limit_usd' => '100']);
    $this->group = Group::factory()->create(['name' => 'Öğrenciler', 'budget_policy_id' => $policy->id]);
});

/** One request costing $0.02 (10 000 input + 1 000 output tokens). */
function exportSpend(User $user, AiModel $model): void
{
    $engine = app(BudgetEngine::class);
    $reservation = $engine->reserve($user, $model, new InputTokenCount(10000, InputCountMethod::ProviderEndpoint, 0.0), 1000);
    $engine->settle($reservation, new Settlement(new TokenUsage(input: 10000, output: 1000)));
}

function csvRows(string $body, string $separator = ','): array
{
    expect(str_starts_with($body, "\u{FEFF}"))->toBeTrue();

    return array_map(fn (string $line) => str_getcsv($line, $separator, escape: ''), explode("\n", trim(substr($body, 3))));
}

test('the breakdown is exported in full, with the page filters', function () {
    // More users than the page shows.
    foreach (range(1, UsageStatistics::TOP_ROWS + 2) as $i) {
        $user = User::factory()->create(['name' => "User {$i}", 'email' => "user{$i}@example.edu", 'group_id' => $this->group->id]);
        exportSpend($user, $this->model);
    }

    $response = $this->actingAs($this->admin)->get(route('admin.reports.export', ['dataset' => 'breakdown', 'by' => 'user', 'from' => '2026-10-01', 'to' => '2026-10-31']));

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertDownload('ada-usage-user-2026-10-01_2026-10-31.csv');

    $rows = csvRows($response->streamedContent());
    expect($rows[0])->toBe(['User', 'E-mail', 'Requests', 'Input tokens', 'Output tokens', 'Cost (USD)'])
        ->and($rows)->toHaveCount(UsageStatistics::TOP_ROWS + 3)
        ->and($rows[1][2])->toBe('1')
        ->and($rows[1][5])->toBe('0.02')
        ->and(AuditLog::query()->where('action', 'reports.exported')->sole()->new_values)->toMatchArray(['dataset' => 'breakdown', 'kind' => 'user']);
});

test('Turkish exports use the separators Turkish Excel expects', function () {
    $this->admin->forceFill(['locale' => 'tr'])->save();
    exportSpend(User::factory()->create(['group_id' => $this->group->id]), $this->model);

    $body = $this->actingAs($this->admin)
        ->get(route('admin.reports.export', ['dataset' => 'breakdown', 'by' => 'group']))
        ->streamedContent();

    expect(csvRows($body, ';'))->toBe([
        ['Grup', 'İstek', 'Girdi token', 'Çıktı token', 'Maliyet (USD)'],
        ['Öğrenciler', '1', '10000', '1000', '0,02'],
    ]);
});

test('the timeline is exported per day or month', function () {
    exportSpend(User::factory()->create(['group_id' => $this->group->id]), $this->model);

    $body = $this->actingAs($this->admin)
        ->get(route('admin.reports.export', ['dataset' => 'timeline', 'from' => '2026-10-14', 'to' => '2026-10-16']))
        ->streamedContent();

    expect(csvRows($body))->toBe([
        ['Day', 'Requests', 'Cost (USD)'],
        ['2026-10-14', '0', '0.00'],
        ['2026-10-15', '1', '0.02'],
        ['2026-10-16', '0', '0.00'],
    ]);
});

test('cells that a spreadsheet would run as formulas are defused', function () {
    exportSpend(User::factory()->create(['name' => '=HYPERLINK("http://evil")', 'group_id' => $this->group->id]), $this->model);

    $rows = csvRows($this->actingAs($this->admin)->get(route('admin.reports.export', ['by' => 'user']))->streamedContent());

    expect($rows[1][0])->toBe('\'=HYPERLINK("http://evil")');
});

test('exports are for administrators and validate like the page', function () {
    $this->actingAs(User::factory()->create())->get(route('admin.reports.export'))->assertForbidden();

    $this->actingAs($this->admin)->get(route('admin.reports.export', ['dataset' => 'messages']))->assertSessionHasErrors('dataset');
    $this->actingAs($this->admin)->get(route('admin.reports.export', ['from' => '2026-10-10', 'to' => '2026-10-01']))->assertSessionHasErrors('to');
});
