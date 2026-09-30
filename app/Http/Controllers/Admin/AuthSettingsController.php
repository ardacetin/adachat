<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Services\IdentityProviderRegistry;
use App\Domain\Institution\Settings\AuthSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAuthSettingsRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AuthSettingsController extends Controller
{
    public function edit(AuthSettings $settings, IdentityProviderRegistry $providers): Response
    {
        return Inertia::render('admin/authentication', [
            'settings' => [
                'allowed_domains' => $settings->allowed_domains,
                'auto_provision' => $settings->auto_provision,
            ],
            // Secrets stay in .env; the UI only shows whether a provider is configured.
            'providers' => $providers->enabledKeys(),
        ]);
    }

    public function update(UpdateAuthSettingsRequest $request, AuthSettings $settings, AuditLogger $audit): RedirectResponse
    {
        $before = $settings->toArray();

        /** @var list<string> $domains */
        $domains = $request->validated('allowed_domains');

        $settings->allowed_domains = $domains;
        $settings->auto_provision = $request->boolean('auto_provision');
        $settings->save();

        [$old, $new] = AuditLogger::diff($before, $settings->toArray());

        if ($new !== []) {
            $audit->record('auth.settings_updated', 'AuthSettings', $old, $new);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.saved')]);

        return to_route('admin.authentication.edit');
    }
}
