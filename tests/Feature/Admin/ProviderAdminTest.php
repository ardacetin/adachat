<?php

use App\Domain\AI\Services\CredentialVault;
use App\Models\AuditLog;
use App\Models\Provider;
use App\Models\ProviderCredential;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

const DUMMY_KEY = 'sk-test-0123456789abcdef8f2a';

function superAdmin(): User
{
    return User::factory()->superAdmin()->create();
}

test('only super admins can manage providers', function (string $route) {
    $provider = Provider::factory()->create();

    $this->actingAs(User::factory()->create())->get(route($route, $provider))->assertForbidden();
    $this->actingAs(User::factory()->admin()->create())->get(route($route, $provider))->assertForbidden();
    $this->actingAs(superAdmin())->get(route($route, $provider))->assertOk();
})->with(['admin.providers.index', 'admin.providers.create', 'admin.providers.edit']);

test('admins cannot write providers or run checks', function () {
    $provider = Provider::factory()->create();
    $this->actingAs(User::factory()->admin()->create());

    $this->post(route('admin.providers.store'), ['slug' => 'x'])->assertForbidden();
    $this->put(route('admin.providers.update', $provider), ['name' => 'x'])->assertForbidden();
    $this->post(route('admin.providers.check', $provider))->assertForbidden();
});

test('a provider is created with an encrypted, masked key', function () {
    $this->actingAs(superAdmin())
        ->post(route('admin.providers.store'), [
            'slug' => 'openai',
            'driver' => 'openai',
            'name' => 'OpenAI',
            'base_url' => '',
            'enabled' => true,
            'api_key' => DUMMY_KEY,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.providers.index'));

    $provider = Provider::query()->sole();
    $credential = $provider->activeCredential()->sole();

    expect($credential->secret)->toBe(DUMMY_KEY)
        ->and($credential->last_four)->toBe('8f2a')
        ->and(DB::table('provider_credentials')->value('secret'))->not->toContain(DUMMY_KEY)
        ->and(AuditLog::query()->pluck('action')->all())->toEqual(['provider.created', 'provider.credential_rotated']);

    // The key never appears in audit rows.
    expect(json_encode(AuditLog::query()->get()->toArray()))->not->toContain(DUMMY_KEY);
});

test('the key is never sent to the browser', function () {
    $provider = Provider::factory()->create();
    app(CredentialVault::class)->rotate($provider, DUMMY_KEY);

    $this->actingAs(superAdmin());

    foreach ([route('admin.providers.index'), route('admin.providers.edit', $provider)] as $url) {
        $response = $this->get($url)->assertOk();
        expect($response->getContent())->not->toContain(DUMMY_KEY)->toContain('8f2a');
    }
});

test('the driver cannot be changed after creation', function () {
    $provider = Provider::factory()->create(['driver' => 'openai']);

    $this->actingAs(superAdmin())
        ->put(route('admin.providers.update', $provider), [
            'slug' => $provider->slug,
            'driver' => 'anthropic',
            'name' => $provider->name,
            'enabled' => true,
        ])
        ->assertSessionHasErrors('driver');
});

test('updating without a key keeps the current credential', function () {
    $provider = Provider::factory()->create();
    app(CredentialVault::class)->rotate($provider, DUMMY_KEY);

    $this->actingAs(superAdmin())
        ->put(route('admin.providers.update', $provider), [
            'slug' => $provider->slug,
            'name' => 'Renamed',
            'enabled' => true,
            'api_key' => '',
        ])
        ->assertSessionHasNoErrors();

    expect(ProviderCredential::query()->count())->toBe(1)
        ->and($provider->refresh()->name)->toBe('Renamed')
        ->and(AuditLog::query()->where('action', 'provider.updated')->sole()->new_values)->toEqual(['name' => 'Renamed']);
});

test('slugs must be unique and well formed', function () {
    Provider::factory()->create(['slug' => 'openai']);
    $this->actingAs(superAdmin());

    foreach (['openai', 'Not A Slug'] as $slug) {
        $this->post(route('admin.providers.store'), [
            'slug' => $slug, 'driver' => 'openai', 'name' => 'X', 'enabled' => true,
        ])->assertSessionHasErrors('slug');
    }
});

test('connection check reports success and failure', function () {
    $provider = Provider::factory()->create(['driver' => 'openai']);
    app(CredentialVault::class)->rotate($provider, DUMMY_KEY);
    $this->actingAs(superAdmin());

    Http::fake(['*' => Http::sequence()
        ->push(['data' => []])
        ->push(['error' => ['message' => 'bad key']], 401)]);

    $this->post(route('admin.providers.check', $provider))
        ->assertInertiaFlash('toast.type', 'success');
    $this->post(route('admin.providers.check', $provider))
        ->assertInertiaFlash('toast.type', 'error')
        ->assertInertiaFlash('toast.message', __('admin.provider_check.failed', ['reason' => __('admin.provider_errors.provider_unavailable')]));

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer '.DUMMY_KEY));
});

test('an OpenAI-compatible provider needs an address but no key', function () {
    $this->actingAs(superAdmin());
    $input = ['slug' => 'ollama', 'driver' => 'openai_compatible', 'name' => 'Ollama', 'base_url' => '', 'enabled' => true];

    $this->post(route('admin.providers.store'), $input)->assertSessionHasErrors('base_url');

    $this->post(route('admin.providers.store'), [...$input, 'base_url' => 'http://ollama:11434/v1'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.providers.index'));

    $provider = Provider::query()->sole();

    expect($provider->driver->value)->toBe('openai_compatible')
        ->and($provider->activeCredential)->toBeNull();

    $this->put(route('admin.providers.update', $provider), [...$input, 'driver' => null, 'base_url' => ''])
        ->assertSessionHasErrors('base_url');
});

test('a keyless OpenAI-compatible connection check sends no credentials', function () {
    $provider = Provider::factory()->create(['driver' => 'openai_compatible', 'base_url' => 'http://ollama:11434/v1']);
    Http::fake(['*' => Http::response(['data' => []])]);

    $this->actingAs(superAdmin())
        ->post(route('admin.providers.check', $provider))
        ->assertInertiaFlash('toast.type', 'success');

    Http::assertSent(fn ($request) => $request->url() === 'http://ollama:11434/v1/models'
        && ! $request->hasHeader('Authorization'));
});
