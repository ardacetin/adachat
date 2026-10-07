<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Oidc\OidcUnavailable;
use App\Domain\Identity\Providers\OidcIdentityProvider;
use App\Domain\Identity\Providers\SamlIdentityProvider;
use App\Domain\Institution\Settings\AuthSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAuthSettingsRequest;
use App\Models\Group;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AuthSettingsController extends Controller
{
    public function edit(AuthSettings $settings, SamlIdentityProvider $saml, OidcIdentityProvider $oidc): Response
    {
        return Inertia::render('admin/authentication', [
            'settings' => [
                'allowed_domains' => $settings->allowed_domains,
                'auto_provision' => $settings->auto_provision,
                'group_mapping' => $settings->group_mapping,
                'group_mapping_unmatched' => $settings->group_mapping_unmatched,
            ],
            'mapped_groups' => Group::query()->whereNotNull('idp_groups')->count(),
            // Values to enter in the IdP, plus the (public) IdP values read from .env.
            'saml' => $saml->setupDetails(),
            'oidc' => $oidc->setupDetails(),
        ]);
    }

    /**
     * Loads the identity provider's discovery document and keys, bypassing
     * the cache, and compares the clocks.
     */
    public function testOidc(OidcIdentityProvider $oidc): RedirectResponse
    {
        try {
            $skew = $oidc->discovery()->test();

            Inertia::flash('toast', $skew !== null && abs($skew) > 30
                ? ['type' => 'error', 'message' => __('admin.oidc_test_skew', ['seconds' => abs($skew)])]
                : ['type' => 'success', 'message' => __('admin.oidc_test_ok')]);
        } catch (OidcUnavailable $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('admin.oidc_test_failed', ['error' => $exception->getMessage()])]);
        }

        return to_route('admin.authentication.edit');
    }

    public function update(UpdateAuthSettingsRequest $request, AuthSettings $settings, AuditLogger $audit): RedirectResponse
    {
        $before = $settings->toArray();

        /** @var list<string> $domains */
        $domains = $request->validated('allowed_domains');

        $settings->allowed_domains = $domains;
        $settings->auto_provision = $request->boolean('auto_provision');

        if ($request->has('group_mapping')) {
            $settings->group_mapping = $request->boolean('group_mapping');
            $settings->group_mapping_unmatched = (string) $request->validated('group_mapping_unmatched', $settings->group_mapping_unmatched);
        }
        $settings->save();

        [$old, $new] = AuditLogger::diff($before, $settings->toArray());

        if ($new !== []) {
            $audit->record('auth.settings_updated', 'AuthSettings', $old, $new);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.saved')]);

        return to_route('admin.authentication.edit');
    }
}
