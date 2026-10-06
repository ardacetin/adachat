<?php

use App\Domain\AI\Data\InputTokenCount;
use App\Domain\AI\Data\TokenUsage;
use App\Domain\AI\Enums\InputCountMethod;
use App\Domain\Budget\Data\Settlement;
use App\Domain\Budget\Services\BudgetEngine;
use App\Domain\Budget\Services\CapAlerts;
use App\Domain\Institution\Settings\InstitutionSettings;
use App\Mail\CapAlert;
use App\Mail\TestMail;
use App\Models\AiModel;
use App\Models\AuditLog;
use App\Models\BudgetPolicy;
use App\Models\Group;
use App\Models\InstitutionPeriod;
use App\Models\User;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;

beforeEach(function () {
    Mail::fake();
    updateSettings(InstitutionSettings::class, [
        'timezone' => 'Europe/Istanbul',
        'default_locale' => 'tr',
        'monthly_cap_usd' => '1',
        'notification_emails' => ['finance@example.edu', 'it@example.edu'],
    ]);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
});

/** Spend $amount (at $1/M input tokens, no output) as one settled request. */
function spendInstitution(string $amount): void
{
    $policy = BudgetPolicy::factory()->create(['monthly_limit_usd' => '100']);
    $user = User::factory()->create(['group_id' => Group::factory()->create(['budget_policy_id' => $policy->id])->id]);
    $model = AiModel::factory()->create(['input_price_per_million' => '1', 'output_price_per_million' => '1', 'max_output_tokens' => 1000]);
    $tokens = BigDecimal::of($amount)->multipliedBy(1_000_000)->toInt();

    $engine = app(BudgetEngine::class);
    $reservation = $engine->reserve($user, $model, new InputTokenCount(1000, InputCountMethod::ProviderEndpoint, 0.0), 300);
    $engine->settle($reservation, new Settlement(new TokenUsage(input: $tokens, output: 0)));
}

test('nothing is sent below 80 %', function () {
    spendInstitution('0.5');

    $this->artisan('ada:budget:cap-alerts')->assertSuccessful();

    Mail::assertNothingSent();
});

test('the 80 % alert is sent once, then the 100 % alert once', function () {
    spendInstitution('0.85');

    $this->artisan('ada:budget:cap-alerts')->expectsOutputToContain('80 %')->assertSuccessful();
    $this->artisan('ada:budget:cap-alerts')->assertSuccessful();

    Mail::assertSent(CapAlert::class, 1);
    Mail::assertSent(CapAlert::class, fn (CapAlert $mail) => $mail->threshold === 80
        && $mail->hasTo('finance@example.edu') && $mail->hasTo('it@example.edu')
        && $mail->usedUsd === '0.85' && $mail->capUsd === '1.00' && $mail->locale === 'tr');

    spendInstitution('0.2');
    $this->artisan('ada:budget:cap-alerts')->assertSuccessful();
    $this->artisan('ada:budget:cap-alerts')->assertSuccessful();

    Mail::assertSent(CapAlert::class, 2);
    Mail::assertSent(CapAlert::class, fn (CapAlert $mail) => $mail->threshold === 100 && $mail->resetsOn === '2026-11-01');
    expect(AuditLog::query()->where('action', 'budget.cap_alert')->count())->toBe(2);
});

test('jumping past 100 % sends only the 100 % alert', function () {
    spendInstitution('1.2');

    $this->artisan('ada:budget:cap-alerts')->assertSuccessful();

    Mail::assertSent(CapAlert::class, 1);
    Mail::assertSent(CapAlert::class, fn (CapAlert $mail) => $mail->threshold === 100);
    expect(InstitutionPeriod::query()->sole()->alerted_80_at)->not->toBeNull();
});

test('without recipients or without a cap nothing is sent or stamped', function () {
    spendInstitution('0.9');
    updateSettings(InstitutionSettings::class, ['notification_emails' => []]);

    $this->artisan('ada:budget:cap-alerts')->assertSuccessful();
    expect(InstitutionPeriod::query()->sole()->alerted_80_at)->toBeNull();

    updateSettings(InstitutionSettings::class, ['notification_emails' => ['it@example.edu'], 'monthly_cap_usd' => null]);
    $this->artisan('ada:budget:cap-alerts')->assertSuccessful();

    Mail::assertNothingSent();
});

test('changing the cap makes the alerts due again', function () {
    $admin = User::factory()->superAdmin()->create();
    spendInstitution('0.9');
    $this->artisan('ada:budget:cap-alerts');
    Mail::assertSent(CapAlert::class, 1);

    $this->actingAs($admin)->post(route('admin.institution.update'), [
        'name' => 'Example University', 'default_locale' => 'tr', 'timezone' => 'Europe/Istanbul',
        'budget_display' => 'amount', 'monthly_cap_usd' => '1,05', 'notification_emails' => "IT@example.edu\nfinance@example.edu, it@example.edu",
    ])->assertSessionHasNoErrors();

    $settings = app(InstitutionSettings::class)->refresh();
    expect($settings->monthly_cap_usd)->toBe('1.05')
        ->and($settings->notification_emails)->toBe(['it@example.edu', 'finance@example.edu'])
        ->and(InstitutionPeriod::query()->sole()->alerted_80_at)->toBeNull();

    $this->artisan('ada:budget:cap-alerts');
    Mail::assertSent(CapAlert::class, 2);
});

test('invalid caps and addresses are rejected', function () {
    $admin = User::factory()->superAdmin()->create();
    $base = ['name' => 'Example University', 'default_locale' => 'tr', 'timezone' => 'Europe/Istanbul', 'budget_display' => 'amount'];

    $this->actingAs($admin)->post(route('admin.institution.update'), [...$base, 'monthly_cap_usd' => '-5'])
        ->assertSessionHasErrors('monthly_cap_usd');
    $this->actingAs($admin)->post(route('admin.institution.update'), [...$base, 'monthly_cap_usd' => '10.555'])
        ->assertSessionHasErrors('monthly_cap_usd');
    $this->actingAs($admin)->post(route('admin.institution.update'), [...$base, 'notification_emails' => 'not-an-address'])
        ->assertSessionHasErrors('notification_emails.0');
});

test('a test e-mail goes to the notification addresses, or to the administrator', function () {
    $admin = User::factory()->superAdmin()->create(['email' => 'admin@example.edu']);

    $this->actingAs($admin)->post(route('admin.institution.test-mail'))->assertRedirect(route('admin.institution.edit'));
    Mail::assertSent(TestMail::class, fn (TestMail $mail) => $mail->hasTo('finance@example.edu') && $mail->hasTo('it@example.edu'));

    updateSettings(InstitutionSettings::class, ['notification_emails' => []]);
    $this->actingAs($admin)->post(route('admin.institution.test-mail'));
    Mail::assertSent(TestMail::class, fn (TestMail $mail) => $mail->hasTo('admin@example.edu'));

    $this->actingAs(User::factory()->admin()->create())->post(route('admin.institution.test-mail'))->assertForbidden();
});

test('the dashboard shows the institution cap', function () {
    spendInstitution('0.25');

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.index'))
        ->assertInertia(fn ($page) => $page->where('cap', [
            'cap_usd' => '1.00',
            'used_usd' => '0.25',
            'percent' => 25,
            'resets_on' => '2026-11-01',
        ]));

    updateSettings(InstitutionSettings::class, ['monthly_cap_usd' => null]);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.index'))
        ->assertInertia(fn ($page) => $page->where('cap', null));
});

test('the cap alert e-mail renders in the institution language', function () {
    $html = (new CapAlert('Örnek Üniversitesi', 100, '1.20', '1.00', '2026-11-01'))->locale('tr')->render();

    expect($html)->toContain('Aylık bütçe tükendi')->toContain('Örnek Üniversitesi')->toContain('$1.00');
});

test('an alert that could not be sent is tried again', function () {
    spendInstitution('0.85');

    // An SMTP server nobody listens on.
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1]);
    Mail::swap(new MailManager(app()));

    expect(fn () => app(CapAlerts::class)->check())->toThrow(TransportException::class);

    expect(InstitutionPeriod::query()->sole()->alerted_80_at)->toBeNull();

    Mail::fake();
    $this->artisan('ada:budget:cap-alerts')->assertSuccessful();

    Mail::assertSent(CapAlert::class, 1);
});
