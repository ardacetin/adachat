<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Institution\Services\BrandingAssets;
use App\Domain\Institution\Settings\InstitutionSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateInstitutionSettingsRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;
use Inertia\Response;

class InstitutionSettingsController extends Controller
{
    private const TEXT_FIELDS = [
        'name', 'short_name', 'domain', 'support_email', 'default_locale',
        'timezone', 'privacy_url', 'terms_url', 'primary_color',
    ];

    /** Upload field => settings property. */
    private const ASSETS = [
        'logo' => 'logo_path',
        'logo_dark' => 'logo_dark_path',
        'favicon' => 'favicon_path',
    ];

    public function edit(InstitutionSettings $settings): Response
    {
        return Inertia::render('admin/institution', [
            'settings' => [
                ...array_combine(self::TEXT_FIELDS, array_map(fn (string $field) => $settings->{$field}, self::TEXT_FIELDS)),
                'has_logo' => $settings->logo_path !== null,
                'has_logo_dark' => $settings->logo_dark_path !== null,
                'has_favicon' => $settings->favicon_path !== null,
            ],
            'timezones' => timezone_identifiers_list(),
        ]);
    }

    public function update(
        UpdateInstitutionSettingsRequest $request,
        InstitutionSettings $settings,
        BrandingAssets $assets,
        AuditLogger $audit,
    ): RedirectResponse {
        $before = $settings->toArray();

        foreach (self::TEXT_FIELDS as $field) {
            $settings->{$field} = $request->validated($field);
        }

        foreach (self::ASSETS as $input => $property) {
            $file = $request->file($input);

            if ($file instanceof UploadedFile) {
                $settings->{$property} = $assets->store($file, $input, $settings->{$property});
            } elseif ($request->boolean('remove_'.$input)) {
                $assets->delete($settings->{$property});
                $settings->{$property} = null;
            }
        }

        $settings->save();

        [$old, $new] = AuditLogger::diff($before, $settings->toArray());

        if ($new !== []) {
            $audit->record('institution.settings_updated', 'InstitutionSettings', $old, $new);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.saved')]);

        return to_route('admin.institution.edit');
    }
}
