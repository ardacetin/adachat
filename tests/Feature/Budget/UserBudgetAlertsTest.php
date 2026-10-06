<?php

use App\Domain\Budget\Money\Usd;
use App\Domain\Budget\Services\BudgetPeriods;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Institution\Settings\InstitutionSettings;
use App\Mail\UserBudgetAlert;
use App\Models\BudgetPeriod;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    updateSettings(InstitutionSettings::class, ['timezone' => 'Europe/Istanbul', 'default_locale' => 'tr', 'budget_display' => 'amount']);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
    $this->user = User::factory()->create(['locale' => 'en']);
});

/** The user's current period with a $10 limit and the given spending. */
function spentOfTen(User $user, string $spent): BudgetPeriod
{
    $period = app(BudgetPeriods::class)->current($user);
    $period->forceFill(['limit_usd' => Usd::of('10'), 'spent_usd' => Usd::of($spent)])->save();

    return $period;
}

test('nothing is sent below 80 %', function () {
    spentOfTen($this->user, '7.99');

    $this->artisan('ada:budget:user-alerts')->assertSuccessful();

    Mail::assertNothingSent();
});

test('80 % and 100 % are each sent once per month, in the user\'s language', function () {
    $period = spentOfTen($this->user, '8.50');

    $this->artisan('ada:budget:user-alerts')->expectsOutputToContain('Sent 1 budget alert')->assertSuccessful();
    $this->artisan('ada:budget:user-alerts')->assertSuccessful();

    Mail::assertSent(UserBudgetAlert::class, 1);
    Mail::assertSent(UserBudgetAlert::class, fn (UserBudgetAlert $mail) => $mail->hasTo($this->user->email)
        && $mail->threshold === 80
        && $mail->locale === 'en'
        && $mail->amounts === ['spent' => '8.50', 'limit' => '10.00']
        && $mail->resetsOn === '2026-11-01');

    $period->forceFill(['spent_usd' => Usd::of('10.20')])->save();
    $this->artisan('ada:budget:user-alerts')->assertSuccessful();
    $this->artisan('ada:budget:user-alerts')->assertSuccessful();

    Mail::assertSent(UserBudgetAlert::class, 2);
    Mail::assertSent(UserBudgetAlert::class, fn (UserBudgetAlert $mail) => $mail->threshold === 100);
});

test('reaching 100 % at once sends only the 100 % alert', function () {
    spentOfTen($this->user, '10');

    $this->artisan('ada:budget:user-alerts')->assertSuccessful();

    Mail::assertSent(UserBudgetAlert::class, 1);
    Mail::assertSent(UserBudgetAlert::class, fn (UserBudgetAlert $mail) => $mail->threshold === 100);
    expect(BudgetPeriod::query()->sole()->alerted_80_at)->not->toBeNull();
});

test('a raised limit announces a threshold again when it is reached', function () {
    $period = spentOfTen($this->user, '8.50');
    $this->artisan('ada:budget:user-alerts');

    $period->forceFill(['limit_usd' => Usd::of('20')])->save();
    $this->artisan('ada:budget:user-alerts');

    expect($period->refresh()->alerted_80_at)->toBeNull();

    $period->forceFill(['spent_usd' => Usd::of('16.50')])->save();
    $this->artisan('ada:budget:user-alerts');

    Mail::assertSent(UserBudgetAlert::class, 2);
});

test('no e-mail for users who turned them off, disabled users or when the institution sends none', function (Closure $setup) {
    $setup($this->user);
    spentOfTen($this->user, '9');

    $this->artisan('ada:budget:user-alerts')->assertSuccessful();

    Mail::assertNothingSent();
})->with([
    'user opted out' => [fn (User $user) => $user->forceFill(['budget_emails' => false])->save()],
    'disabled user' => [fn (User $user) => $user->forceFill(['status' => UserStatus::Disabled])->save()],
    'institution off' => [fn () => updateSettings(InstitutionSettings::class, ['user_budget_emails' => false])],
]);

test('with percentages only, the e-mail shows no amounts', function () {
    updateSettings(InstitutionSettings::class, ['budget_display' => 'percent']);
    spentOfTen($this->user, '9');

    $this->artisan('ada:budget:user-alerts');

    Mail::assertSent(UserBudgetAlert::class, fn (UserBudgetAlert $mail) => $mail->amounts === null);
});

test('the e-mail renders in the user\'s language', function () {
    $html = (new UserBudgetAlert('Örnek Üniversitesi', 80, ['spent' => '8.50', 'limit' => '10.00'], '2026-11-01'))->locale('tr')->render();

    expect($html)->toContain('Aylık bütçenizin %80')->toContain('$8.50 / $10.00')->toContain('2026-11-01');
});

test('users choose budget e-mails in their settings', function () {
    $this->actingAs($this->user)
        ->get(route('notifications.edit'))
        ->assertInertia(fn ($page) => $page->component('settings/notifications')->where('budgetEmails', true)->where('budgetEmailsOffered', true));

    $this->actingAs($this->user)
        ->put(route('notifications.update'), ['budget_emails' => false])
        ->assertRedirect(route('notifications.edit'));

    expect($this->user->refresh()->budget_emails)->toBeFalse();
});

test('administrators turn budget e-mails off for the institution', function () {
    $admin = User::factory()->superAdmin()->create();
    $settings = app(InstitutionSettings::class);

    $this->actingAs($admin)
        ->post(route('admin.institution.update'), [
            'name' => $settings->name,
            'default_locale' => $settings->default_locale,
            'timezone' => $settings->timezone,
            'budget_display' => $settings->budget_display,
            'user_budget_emails' => false,
        ])
        ->assertSessionHasNoErrors();

    expect(app(InstitutionSettings::class)->refresh()->user_budget_emails)->toBeFalse();
});

test('an alert that could not be sent is tried again', function () {
    $period = spentOfTen($this->user, '10');

    // An SMTP server nobody listens on.
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1]);
    Mail::swap(new MailManager(app()));

    $this->artisan('ada:budget:user-alerts')->assertSuccessful();

    expect($period->refresh()->alerted_100_at)->toBeNull()
        ->and($period->alerted_80_at)->toBeNull();

    Mail::fake();
    $this->artisan('ada:budget:user-alerts')->assertSuccessful();

    Mail::assertSent(UserBudgetAlert::class, fn (UserBudgetAlert $mail) => $mail->threshold === 100);
});
