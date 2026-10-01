<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Budget\Services\CapAlerts;
use App\Domain\Institution\Services\BrandingAssets;
use App\Domain\Institution\Settings\InstitutionSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateInstitutionSettingsRequest;
use App\Mail\TestMail;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class InstitutionSettingsController extends Controller
{
    private const TEXT_FIELDS = [
        'name', 'short_name', 'domain', 'support_email', 'default_locale',
        'timezone', 'privacy_url', 'terms_url', 'primary_color', 'budget_display',
        'monthly_cap_usd',
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
                'notification_emails' => $settings->notification_emails,
                'has_logo' => $settings->logo_path !== null,
                'has_logo_dark' => $settings->logo_dark_path !== null,
                'has_favicon' => $settings->favicon_path !== null,
            ],
            'timezones' => timezone_identifiers_list(),
            'mailConfigured' => ! in_array(config('mail.default'), ['log', 'array'], true),
        ]);
    }

    public function update(
        UpdateInstitutionSettingsRequest $request,
        InstitutionSettings $settings,
        BrandingAssets $assets,
        AuditLogger $audit,
        CapAlerts $alerts,
    ): RedirectResponse {
        $before = $settings->toArray();

        foreach (self::TEXT_FIELDS as $field) {
            $settings->{$field} = $request->validated($field);
        }

        // Absent (older clients): keep the addresses; an empty field clears them.
        if ($request->exists('notification_emails')) {
            $settings->notification_emails = array_values((array) ($request->validated('notification_emails') ?? []));
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

        // A new cap may make this month's alerts due again.
        if ($before['monthly_cap_usd'] !== $settings->monthly_cap_usd) {
            $alerts->reset();
        }

        [$old, $new] = AuditLogger::diff($before, $settings->toArray());

        if ($new !== []) {
            $audit->record('institution.settings_updated', 'InstitutionSettings', $old, $new);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.saved')]);

        return to_route('admin.institution.edit');
    }

    /**
     * Send a test message to the notification addresses (or, without any,
     * to the administrator) to check the mail configuration.
     */
    public function testMail(Request $request, InstitutionSettings $settings): RedirectResponse
    {
        $user = $request->user();
        $recipients = $settings->notification_emails !== [] ? $settings->notification_emails : [$user instanceof User ? $user->email : ''];

        try {
            Mail::to($recipients)->locale($settings->default_locale)->send(new TestMail($settings->name));
            Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.test_mail_sent', ['to' => implode(', ', $recipients)])]);
        } catch (Throwable $e) {
            Log::warning('Test e-mail failed.', ['error' => $e->getMessage()]);
            Inertia::flash('toast', ['type' => 'error', 'message' => __('admin.test_mail_failed')]);
        }

        return to_route('admin.institution.edit');
    }
}
