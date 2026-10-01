<?php

use App\Domain\AI\Data\InputTokenCount;
use App\Domain\AI\Data\TokenUsage;
use App\Domain\AI\Enums\InputCountMethod;
use App\Domain\Budget\Data\Settlement;
use App\Domain\Budget\Services\BudgetEngine;
use App\Domain\Institution\Settings\InstitutionSettings;
use App\Domain\Reports\ReportSettings;
use App\Mail\MonthlyReportMail;
use App\Models\AiModel;
use App\Models\BudgetPolicy;
use App\Models\Group;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule as Scheduler;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    updateSettings(InstitutionSettings::class, [
        'name' => 'Örnek Üniversitesi',
        'timezone' => 'Europe/Istanbul',
        'default_locale' => 'tr',
        'monthly_cap_usd' => '10',
        'notification_emails' => ['finans@example.edu'],
    ]);
    updateSettings(ReportSettings::class, ['last_monthly_sent' => null]);

    $this->model = AiModel::factory()->create(['display_name' => 'GPT Test', 'input_price_per_million' => '1', 'output_price_per_million' => '10']);
    $policy = BudgetPolicy::factory()->create(['monthly_limit_usd' => '100']);
    $this->group = Group::factory()->create(['name' => 'Akademik', 'budget_policy_id' => $policy->id]);
});

/** One request costing $0.02 at the given moment (UTC). */
function reportSpend(User $user, AiModel $model, string $at): void
{
    test()->travelTo(CarbonImmutable::parse($at, 'UTC'));
    $engine = app(BudgetEngine::class);
    $reservation = $engine->reserve($user, $model, new InputTokenCount(10000, InputCountMethod::ProviderEndpoint, 0.0), 1000);
    $engine->settle($reservation, new Settlement(new TokenUsage(input: 10000, output: 1000)));
}

test('last month is sent once, after it ended in the institution time zone', function () {
    $user = User::factory()->create(['group_id' => $this->group->id]);
    reportSpend($user, $this->model, '2026-09-10 12:00:00');
    reportSpend($user, $this->model, '2026-09-30 22:30:00');   // 1 October in Istanbul: next month

    // 30 September 23:00 in Istanbul: September has not ended yet.
    $this->travelTo(CarbonImmutable::parse('2026-09-30 20:00:00', 'UTC'));
    $this->artisan('ada:reports:monthly')->assertSuccessful();
    Mail::assertSent(MonthlyReportMail::class, fn (MonthlyReportMail $mail) => $mail->month === '2026-08');
    Mail::assertSent(MonthlyReportMail::class, 1);

    $this->travelTo(CarbonImmutable::parse('2026-10-01 06:00:00', 'UTC'));
    $this->artisan('ada:reports:monthly')->expectsOutputToContain('2026-09')->assertSuccessful();
    $this->artisan('ada:reports:monthly')->assertSuccessful();

    Mail::assertSent(MonthlyReportMail::class, 2);
    $mail = Mail::sent(MonthlyReportMail::class)->last();
    expect($mail->month)->toBe('2026-09')
        ->and($mail->hasTo('finans@example.edu'))->toBeTrue()
        ->and($mail->locale)->toBe('tr')
        ->and($mail->summary['totals']['requests'])->toBe(1)
        ->and($mail->summary['totals']['cost_usd'])->toBe('0.02')
        ->and($mail->summary['groups'][0]['label'])->toBe('Akademik')
        ->and($mail->summary['cap'])->toBe(['cap_usd' => '10.00', 'percent' => 1]);
    expect(app(ReportSettings::class)->refresh()->last_monthly_sent)->toBe('2026-09');
});

test('nothing is sent or marked without recipients', function () {
    updateSettings(InstitutionSettings::class, ['notification_emails' => []]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'UTC'));

    $this->artisan('ada:reports:monthly')->assertSuccessful();

    Mail::assertNothingSent();
    expect(app(ReportSettings::class)->refresh()->last_monthly_sent)->toBeNull();
});

test('any month can be sent on demand without changing the schedule', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'UTC'));

    $this->artisan('ada:reports:monthly', ['--month' => '2026-07', '--to' => ['rektorluk@example.edu']])->assertSuccessful();

    Mail::assertSent(MonthlyReportMail::class, fn (MonthlyReportMail $mail) => $mail->month === '2026-07' && $mail->hasTo('rektorluk@example.edu') && ! $mail->hasTo('finans@example.edu'));
    expect(app(ReportSettings::class)->refresh()->last_monthly_sent)->toBeNull();

    $this->artisan('ada:reports:monthly', ['--month' => '2026-13'])->assertFailed();
});

test('the report renders in the institution language with its attachments', function () {
    $user = User::factory()->create(['name' => 'Ayşe Yılmaz', 'email' => 'ayse@example.edu', 'group_id' => $this->group->id]);
    reportSpend($user, $this->model, '2026-09-10 12:00:00');
    $this->travelTo(CarbonImmutable::parse('2026-10-01 06:00:00', 'UTC'));

    $this->artisan('ada:reports:monthly')->assertSuccessful();
    $mail = Mail::sent(MonthlyReportMail::class)->sole();

    $html = $mail->locale('tr')->render();
    expect($html)->toContain('2026-09 kullanım raporu')->toContain('Akademik')->toContain('$0.02')->toContain('Kurum tavanı kullanımı');

    $attachments = collect($mail->attachments())->mapWithKeys(fn ($attachment) => [$attachment->as => $attachment]);
    expect($attachments->keys()->all())->toBe(['ada-usage-user-2026-09.csv', 'ada-usage-day-2026-09.csv']);

    $csv = '';
    $attachments['ada-usage-user-2026-09.csv']->attachWith(fn () => null, function ($data) use (&$csv) {
        $csv = $data();
    });
    expect($csv)->toContain('"Ayşe Yılmaz";ayse@example.edu;1;10000;1000;0,02');
});

test('the monthly report is scheduled hourly', function () {
    $events = collect(app(Scheduler::class)->events())->map->command->filter()->implode(' ');

    expect($events)->toContain('ada:reports:monthly');
});
