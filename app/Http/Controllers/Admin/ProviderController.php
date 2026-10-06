<?php

namespace App\Http\Controllers\Admin;

use App\Domain\AI\Actions\CheckProviderConnection;
use App\Domain\AI\Enums\ProviderDriver;
use App\Domain\AI\Services\CredentialVault;
use App\Domain\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProviderRequest;
use App\Models\Provider;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ProviderController extends Controller
{
    public function index(): Response
    {
        $providers = Provider::query()
            ->with('activeCredential')
            ->withCount('models')
            ->orderBy('name')
            ->get()
            ->map(fn (Provider $provider) => [
                'id' => $provider->id,
                'slug' => $provider->slug,
                'name' => $provider->name,
                'driver' => $provider->driver->label(),
                'enabled' => $provider->enabled,
                'models_count' => $provider->models_count,
                'masked_key' => CredentialVault::mask($provider->activeCredential),
                'uses_env_key' => $provider->activeCredential === null
                    && $provider->usesEnvKeyAddress()
                    && filled(config('ada.providers.env_keys.'.$provider->driver->value)),
            ]);

        return Inertia::render('admin/providers/index', ['providers' => $providers]);
    }

    public function create(): Response
    {
        return $this->form(null);
    }

    public function edit(Provider $provider): Response
    {
        return $this->form($provider);
    }

    public function store(ProviderRequest $request, CredentialVault $vault, AuditLogger $audit): RedirectResponse
    {
        $provider = new Provider($request->safe()->only(['slug', 'driver', 'name', 'base_url', 'enabled']));
        $provider->save();

        $audit->record('provider.created', $provider, [], $provider->only(['slug', 'driver', 'name', 'base_url', 'enabled']));

        if ($request->filled('api_key')) {
            $vault->rotate($provider, $request->string('api_key')->value());
        }

        return $this->saved();
    }

    public function update(ProviderRequest $request, Provider $provider, CredentialVault $vault, AuditLogger $audit): RedirectResponse
    {
        $before = $provider->only(['slug', 'name', 'base_url', 'enabled']);

        $provider->fill($request->safe()->only(['slug', 'name', 'base_url', 'enabled']))->save();

        [$old, $new] = AuditLogger::diff($before, $provider->only(['slug', 'name', 'base_url', 'enabled']));

        if ($new !== []) {
            $audit->record('provider.updated', $provider, $old, $new);
        }

        if ($request->filled('api_key')) {
            $vault->rotate($provider, $request->string('api_key')->value());
        }

        return $this->saved();
    }

    public function check(Provider $provider, CheckProviderConnection $check): RedirectResponse
    {
        $error = $check->handle($provider);

        Inertia::flash('toast', $error === null
            ? ['type' => 'success', 'message' => __('admin.provider_check.ok')]
            : ['type' => 'error', 'message' => __('admin.provider_check.failed', ['reason' => __("admin.provider_errors.{$error}")])]);

        return back();
    }

    private function form(?Provider $provider): Response
    {
        $provider?->load('activeCredential');

        return Inertia::render('admin/providers/form', [
            'provider' => $provider === null ? null : [
                'id' => $provider->id,
                'slug' => $provider->slug,
                'driver' => $provider->driver->value,
                'name' => $provider->name,
                'base_url' => $provider->base_url,
                'enabled' => $provider->enabled,
                'masked_key' => CredentialVault::mask($provider->activeCredential),
            ],
            'drivers' => array_map(
                fn (ProviderDriver $driver) => ['value' => $driver->value, 'label' => $driver->label(), 'base_url' => $driver->defaultBaseUrl()],
                ProviderDriver::cases(),
            ),
        ]);
    }

    private function saved(): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.saved')]);

        return to_route('admin.providers.index');
    }
}
