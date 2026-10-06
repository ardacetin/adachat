<?php

use App\Domain\Institution\Settings\ContentSettings;
use App\Domain\Institution\Settings\InstitutionSettings;
use App\Mail\UserInvitation;
use App\Models\AuditLog;
use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

test('only super admins edit the texts', function (string $role, int $status) {
    $user = match ($role) {
        'user' => User::factory()->create(),
        'admin' => User::factory()->admin()->create(),
        'super_admin' => User::factory()->superAdmin()->create(),
    };

    $this->actingAs($user)->get(route('admin.texts.edit'))->assertStatus($status);
    $this->actingAs($user)->put(route('admin.texts.update'), ['landing' => [], 'invitation' => []])
        ->assertStatus($status === 200 ? 302 : $status);
})->with([
    'user' => ['user', 403],
    'admin' => ['admin', 403],
    'super admin' => ['super_admin', 200],
]);

test('the page shows the defaults and what was saved', function () {
    updateSettings(ContentSettings::class, ['landing' => ['tr' => ['title' => 'Özel başlık']]]);

    $this->actingAs(User::factory()->superAdmin()->create())
        ->get(route('admin.texts.edit'))
        ->assertInertia(fn ($page) => $page
            ->component('admin/texts')
            ->where('locales', ['en', 'tr'])
            ->where('landing.defaults.en.title', "Your institution's AI assistant")
            ->where('landing.defaults.tr.footer', ':app açık kaynaklıdır ve :institution tarafından barındırılır.')
            ->where('landing.values.tr.title', 'Özel başlık')
            ->where('invitation.defaults.en.subject', 'Your :app account has been created')
            ->where('invitation.values', []));
});

test('saved texts replace the defaults, field by field, with placeholders filled in', function () {
    updateSettings(InstitutionSettings::class, ['name' => 'Beykoz Üniversitesi', 'default_locale' => 'tr']);
    $admin = User::factory()->superAdmin()->create();

    $this->actingAs($admin)->put(route('admin.texts.update'), [
        'landing' => [
            'tr' => ['title' => ':institution için yapay zekâ', 'lead' => '   '],
            'en' => ['title' => ''],
        ],
        'invitation' => ['tr' => ['subject' => ':app hesabınız hazır, :email']],
    ])->assertRedirect(route('admin.texts.edit'));

    // Blank fields are not stored: they keep the default.
    $settings = app(ContentSettings::class)->refresh();
    expect($settings->landing)->toBe(['tr' => ['title' => ':institution için yapay zekâ']])
        ->and($settings->invitation_email)->toBe(['tr' => ['subject' => ':app hesabınız hazır, :email']])
        ->and(AuditLog::query()->where('action', 'content.texts_updated')->exists())->toBeTrue();

    // Guests get the institution's language: the custom title, the default lead.
    auth()->logout();
    $this->get(route('home'))->assertInertia(fn ($page) => $page
        ->where('texts.title', 'Beykoz Üniversitesi için yapay zekâ')
        ->where('texts.lead', fn (string $lead) => str_starts_with($lead, 'Beykoz Üniversitesi hesabınızla'))
        ->where('texts.footer', 'Ada Chat açık kaynaklıdır ve Beykoz Üniversitesi tarafından barındırılır.'));

    // The invitation uses the custom subject and the default body.
    Mail::fake();
    $this->actingAs($admin)->post(route('admin.users.store'), ['emails' => 'grace@example.edu', 'group_id' => Group::default()->id, 'role' => 'user']);
    Mail::assertSent(UserInvitation::class, fn (UserInvitation $mail) => $mail->envelope()->subject === 'Ada Chat hesabınız hazır, grace@example.edu'
        && str_contains($mail->render(), 'Beykoz Üniversitesi bünyesinde'));
});

test('texts are limited in length', function () {
    $this->actingAs(User::factory()->superAdmin()->create())
        ->put(route('admin.texts.update'), ['landing' => ['en' => ['title' => str_repeat('x', 201)]]])
        ->assertSessionHasErrors('landing.en.title');
});
