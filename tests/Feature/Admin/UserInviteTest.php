<?php

use App\Domain\Identity\Services\IdentityProviderRegistry;
use App\Domain\Institution\Settings\AuthSettings;
use App\Domain\Institution\Settings\InstitutionSettings;
use App\Http\Controllers\Admin\UserController;
use App\Mail\UserInvitation;
use App\Models\AuditLog;
use App\Models\Group;
use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeIdentityProvider;

beforeEach(function () {
    updateSettings(AuthSettings::class, ['allowed_domains' => ['example.edu'], 'auto_provision' => false]);
    $this->idp = new FakeIdentityProvider(requiresHostedDomain: false);
    $this->app->instance(IdentityProviderRegistry::class, new IdentityProviderRegistry([$this->idp]));
});

function signInAsIdentity(array $overrides = []): TestResponse
{
    test()->idp->next = FakeIdentityProvider::identity(['hostedDomain' => null, ...$overrides]);

    return test()->post(route('auth.acs', 'saml'));
}

test('addresses are parsed from lines, commas and name forms', function () {
    $parsed = UserController::parseAddresses("ada@example.edu\nGrace Hopper <Grace@Example.edu>, \"Alan Turing\" <alan@partner.org>;\n\nnot-an-address\nada@example.edu");

    expect($parsed['valid'])->toBe([
        ['email' => 'ada@example.edu', 'name' => null],
        ['email' => 'grace@example.edu', 'name' => 'Grace Hopper'],
        ['email' => 'alan@partner.org', 'name' => 'Alan Turing'],
    ])->and($parsed['invalid'])->toBe(['not-an-address']);
});

test('administrators add users by e-mail address', function () {
    $admin = User::factory()->admin()->create();
    $group = Group::factory()->create();
    User::factory()->create(['email' => 'existing@example.edu']);

    $this->actingAs($admin)->post(route('admin.users.store'), [
        'emails' => "Grace Hopper <grace@example.edu>\nexisting@example.edu\nalan@partner.org",
        'group_id' => $group->id,
        'role' => 'user',
        'send_email' => false,
    ])->assertRedirect()->assertInertiaFlash('toast.message', __('admin.users.invited', ['created' => 2, 'existing' => 1]));

    $grace = User::query()->where('email', 'grace@example.edu')->sole();

    expect($grace->name)->toBe('Grace Hopper')
        ->and($grace->group_id)->toBe($group->id)
        ->and($grace->invited_by)->toBe($admin->id)
        ->and($grace->invitationPending())->toBeTrue()
        ->and(User::query()->where('email', 'alan@partner.org')->value('name'))->toBe('alan')
        ->and(AuditLog::query()->where('action', 'user.invited')->count())->toBe(2);

    $this->get(route('admin.users.index'))->assertInertia(fn ($page) => $page
        ->where('access.auto_provision', false)
        ->where('users.data', fn ($rows) => collect($rows)->firstWhere('email', 'grace@example.edu')['invitation_pending'] === true));
});

test('invalid input is refused without creating anyone', function (string $emails, string $error) {
    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.users.store'), ['emails' => $emails, 'group_id' => Group::default()->id, 'role' => 'user'])
        ->assertSessionHasErrors(['emails' => $error]);

    expect(User::query()->count())->toBe(1);
})->with([
    'bad address' => ['ada@example.edu, nope', 'These are not valid e-mail addresses: nope'],
    'only separators' => [" ,\n;", 'Enter between 1 and 500 e-mail addresses.'],
]);

test('only super administrators add administrators', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.users.store'), ['emails' => 'boss@example.edu', 'group_id' => Group::default()->id, 'role' => 'admin'])
        ->assertSessionHasErrors('role');

    $this->actingAs(User::factory()->superAdmin()->create())
        ->post(route('admin.users.store'), ['emails' => 'boss@example.edu', 'group_id' => Group::default()->id, 'role' => 'admin'])
        ->assertSessionHasNoErrors();

    expect(User::query()->where('email', 'boss@example.edu')->value('role')->value)->toBe('admin');

    $this->actingAs(User::factory()->create())
        ->post(route('admin.users.store'), ['emails' => 'x@example.edu', 'group_id' => Group::default()->id, 'role' => 'user'])
        ->assertForbidden();
});

test('an added address signs in and is linked, even outside the allowed domains', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->post(route('admin.users.store'), ['emails' => 'alan@partner.org', 'group_id' => Group::default()->id, 'role' => 'user']);
    auth()->logout();

    signInAsIdentity(['email' => 'alan@partner.org', 'subject' => 'alan-1', 'name' => 'Alan Turing'])->assertRedirect(route('home'));

    $alan = User::query()->where('email', 'alan@partner.org')->sole();
    $this->assertAuthenticatedAs($alan);
    expect($alan->invitationPending())->toBeFalse()
        ->and($alan->name)->toBe('Alan Turing')
        ->and(UserIdentity::query()->where('user_id', $alan->id)->value('subject'))->toBe('alan-1');
});

test('without auto provisioning, unlisted addresses are refused', function () {
    signInAsIdentity(['email' => 'stranger@example.edu'])
        ->assertSessionHasErrors(['auth' => __('auth.errors.not_provisioned')]);

    signInAsIdentity(['email' => 'stranger@other.org'])
        ->assertSessionHasErrors(['auth' => __('auth.errors.domain_not_allowed')]);

    expect(User::query()->count())->toBe(0);
});

test('an added address still needs a verified e-mail', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.users.store'), ['emails' => 'alan@partner.org', 'group_id' => Group::default()->id, 'role' => 'user']);
    auth()->logout();

    signInAsIdentity(['email' => 'alan@partner.org', 'emailVerified' => false])
        ->assertSessionHasErrors(['auth' => __('auth.errors.email_not_verified')]);
});

test('unused invitations can be removed, used accounts cannot', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->post(route('admin.users.store'), ['emails' => "unused@example.edu\nused@example.edu", 'group_id' => Group::default()->id, 'role' => 'user']);
    $unused = User::query()->where('email', 'unused@example.edu')->sole();
    $used = User::query()->where('email', 'used@example.edu')->sole();
    $used->forceFill(['last_login_at' => now()])->save();

    $this->get(route('admin.users.show', $unused))->assertInertia(fn ($page) => $page
        ->where('user.invitation_pending', true)
        ->where('permissions.removeInvitation', true));

    $this->delete(route('admin.users.destroy', $unused))->assertRedirect(route('admin.users.index'));
    $this->delete(route('admin.users.destroy', $used))->assertForbidden();
    $this->delete(route('admin.users.destroy', $admin))->assertForbidden();

    expect(User::query()->whereKey($unused->id)->exists())->toBeFalse()
        ->and(User::query()->whereKey($used->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'user.invitation_removed')->sole()->old_values)->toBe(['email' => 'unused@example.edu']);
});

test('administrators cannot remove invited super administrators', function () {
    $super = User::factory()->superAdmin()->create(['invited_at' => now()]);

    $this->actingAs(User::factory()->admin()->create())
        ->delete(route('admin.users.destroy', $super))
        ->assertForbidden();
});

test('new users can be sent an invitation e-mail in the institution\'s language', function () {
    Mail::fake();
    updateSettings(InstitutionSettings::class, ['name' => 'Beykoz Üniversitesi', 'default_locale' => 'tr']);
    $admin = User::factory()->admin()->create(['name' => 'Ayşe Yılmaz']);
    User::factory()->create(['email' => 'existing@example.edu']);

    $this->actingAs($admin)->post(route('admin.users.store'), [
        'emails' => "grace@example.edu\nexisting@example.edu",
        'group_id' => Group::default()->id,
        'role' => 'user',
        'send_email' => true,
    ])->assertRedirect()->assertInertiaFlash('toast.type', 'success');

    // Only the new account is told, in Turkish, by whom.
    Mail::assertSentCount(1);
    Mail::assertSent(UserInvitation::class, function (UserInvitation $mail) {
        $html = $mail->render();

        return $mail->hasTo('grace@example.edu')
            && $mail->locale === 'tr'
            && $mail->invitedBy === 'Ayşe Yılmaz'
            && $mail->envelope()->subject === 'Ada Chat hesabınız oluşturuldu'
            && str_contains($html, 'Beykoz Üniversitesi')
            && str_contains($html, route('home'));
    });

    expect(AuditLog::query()->where('action', 'user.invited')->sole()->new_values['email_sent'])->toBeTrue();
});

test('names in the invitation e-mail cannot become links', function () {
    Mail::fake();
    $admin = User::factory()->admin()->create(['name' => '[Reset your password](https://evil.example)']);

    $this->actingAs($admin)->post(route('admin.users.store'), [
        'emails' => 'grace@example.edu',
        'group_id' => Group::default()->id,
        'role' => 'user',
        'send_email' => true,
    ])->assertRedirect();

    Mail::assertSent(UserInvitation::class, function (UserInvitation $mail) {
        $html = $mail->render();

        return ! str_contains($html, 'href="https://evil.example"')
            && str_contains($html, 'Reset your password');
    });
});

test('invitation e-mails are sent unless turned off', function () {
    Mail::fake();
    $admin = User::factory()->admin()->create();

    // A form without the field (e.g. an old frontend build) still sends.
    $this->actingAs($admin)->post(route('admin.users.store'), [
        'emails' => 'grace@example.edu',
        'group_id' => Group::default()->id,
        'role' => 'user',
    ])->assertRedirect();
    Mail::assertSent(UserInvitation::class, fn (UserInvitation $mail) => $mail->hasTo('grace@example.edu'));

    $this->post(route('admin.users.store'), [
        'emails' => 'alan@example.edu',
        'group_id' => Group::default()->id,
        'role' => 'user',
        'send_email' => false,
    ])->assertRedirect();
    Mail::assertSentCount(1);
});

test('a failed invitation e-mail keeps the account and warns the administrator', function () {
    Mail::shouldReceive('to')->andThrow(new RuntimeException('Connection could not be established with host smtp.example.edu'));

    $this->actingAs(User::factory()->admin()->create())->post(route('admin.users.store'), [
        'emails' => 'grace@example.edu',
        'group_id' => Group::default()->id,
        'role' => 'user',
        'send_email' => true,
    ])->assertRedirect()
        ->assertInertiaFlash('toast.type', 'warning')
        ->assertInertiaFlash('toast.message', __('admin.users.invited', ['created' => 1, 'existing' => 0]).' '.__('admin.users.invitations_failed', ['count' => 1]));

    expect(User::query()->where('email', 'grace@example.edu')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'user.invited')->sole()->new_values['email_sent'])->toBeFalse();
});

test('an invitation can be e-mailed again until the account is used', function () {
    Mail::fake();
    $admin = User::factory()->admin()->create();

    // Added earlier, without an e-mail; adding the address again skips it.
    $this->actingAs($admin)->post(route('admin.users.store'), ['emails' => 'grace@example.edu', 'group_id' => Group::default()->id, 'role' => 'user', 'send_email' => false]);
    $this->post(route('admin.users.store'), ['emails' => 'grace@example.edu', 'group_id' => Group::default()->id, 'role' => 'user', 'send_email' => true])
        ->assertInertiaFlash('toast.message', __('admin.users.invited', ['created' => 0, 'existing' => 1]).' '.__('admin.users.existing_not_emailed'));
    Mail::assertNothingSent();

    $grace = User::query()->where('email', 'grace@example.edu')->sole();
    $this->get(route('admin.users.show', $grace))->assertInertia(fn ($page) => $page->where('permissions.sendInvitation', true));

    $this->post(route('admin.users.invitation', $grace))
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', __('admin.users.invitation_sent', ['email' => 'grace@example.edu']));
    Mail::assertSent(UserInvitation::class, fn (UserInvitation $mail) => $mail->hasTo('grace@example.edu'));
    expect(AuditLog::query()->where('action', 'user.invitation_sent')->sole()->new_values['email_sent'])->toBeTrue();

    // Once the person has signed in, there is nothing to send.
    auth()->logout();
    signInAsIdentity(['email' => 'grace@example.edu', 'subject' => 'grace-sub'])->assertRedirect(route('home'));
    auth()->logout();
    $this->actingAs($admin)->post(route('admin.users.invitation', $grace))->assertForbidden();
    Mail::assertSentCount(1);
});

test('users cannot send invitations', function () {
    $pending = User::factory()->create(['invited_at' => now()]);

    $this->actingAs(User::factory()->create())->post(route('admin.users.invitation', $pending))->assertForbidden();
});
