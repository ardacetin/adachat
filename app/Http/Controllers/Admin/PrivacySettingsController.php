<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Institution\Settings\PrivacySettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The usage notice (first-sign-in acknowledgment) and data retention.
 */
class PrivacySettingsController extends Controller
{
    public function edit(PrivacySettings $settings): Response
    {
        return Inertia::render('admin/privacy', [
            'settings' => [
                'acknowledgment_enabled' => $settings->acknowledgment_enabled,
                'acknowledgment_text' => $settings->acknowledgment_text ?? [],
                'acknowledgment_version' => $settings->acknowledgment_version,
                'conversation_retention_days' => $settings->conversation_retention_days,
                'deleted_conversation_days' => $settings->deleted_conversation_days,
                'usage_retention_months' => $settings->usage_retention_months,
            ],
        ]);
    }

    public function update(Request $request, PrivacySettings $settings, AuditLogger $audit): RedirectResponse
    {
        $rules = [
            'acknowledgment_enabled' => ['required', 'boolean'],
            'acknowledgment_text' => ['nullable', 'array'],
            'ask_again' => ['boolean'],
            // Empty: conversations are kept.
            'conversation_retention_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'deleted_conversation_days' => ['required', 'integer', 'min:0', 'max:365'],
            // Accounting needs at least a year of history.
            'usage_retention_months' => ['required', 'integer', 'min:12', 'max:120'],
        ];

        foreach (config('ada.locales.available') as $locale) {
            $rules["acknowledgment_text.{$locale}"] = ['nullable', 'string', 'max:5000'];
        }

        $validated = $request->validate($rules);
        $before = $settings->toArray();

        $texts = [];

        foreach ((array) ($validated['acknowledgment_text'] ?? []) as $locale => $text) {
            if (is_string($locale) && is_string($text) && trim($text) !== '') {
                $texts[$locale] = trim($text);
            }
        }

        $texts = $texts === [] ? null : $texts;

        $enabled = $request->boolean('acknowledgment_enabled');
        // Everyone acknowledges again when the notice changes, is switched
        // on, or the administrator asks for it.
        $askAgain = $request->boolean('ask_again')
            || $texts !== $settings->acknowledgment_text
            || ($enabled && ! $settings->acknowledgment_enabled);

        $settings->acknowledgment_enabled = $enabled;
        $settings->acknowledgment_text = $texts;
        $settings->acknowledgment_version += $askAgain ? 1 : 0;
        $settings->conversation_retention_days = isset($validated['conversation_retention_days']) ? (int) $validated['conversation_retention_days'] : null;
        $settings->deleted_conversation_days = (int) $validated['deleted_conversation_days'];
        $settings->usage_retention_months = (int) $validated['usage_retention_months'];
        $settings->save();

        // The administrator who wrote the notice has read it.
        $request->user()?->forceFill([
            'acknowledged_version' => $settings->acknowledgment_version,
            'acknowledged_at' => now(),
        ])->save();

        [$old, $new] = AuditLogger::diff($before, $settings->toArray());

        if ($new !== []) {
            $audit->record('privacy.settings_updated', 'PrivacySettings', $old, $new);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.saved')]);

        return to_route('admin.privacy.edit');
    }
}
