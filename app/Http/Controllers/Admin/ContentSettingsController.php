<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Institution\Services\ContentTexts;
use App\Domain\Institution\Settings\ContentSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateContentSettingsRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → Texts: reword the landing page and the invitation e-mail per
 * language. Empty fields keep Ada's defaults.
 */
class ContentSettingsController extends Controller
{
    public function edit(ContentSettings $settings, ContentTexts $texts): Response
    {
        return Inertia::render('admin/texts', [
            'locales' => config('ada.locales.available'),
            'landing' => [
                'fields' => ContentTexts::LANDING,
                'values' => $settings->landing,
                'defaults' => $texts->defaults('landing'),
            ],
            'invitation' => [
                'fields' => ContentTexts::INVITATION,
                'values' => $settings->invitation_email,
                'defaults' => $texts->defaults('invitation'),
            ],
        ]);
    }

    public function update(UpdateContentSettingsRequest $request, ContentSettings $settings, AuditLogger $audit): RedirectResponse
    {
        $before = $settings->toArray();

        $settings->landing = $request->texts('landing', ContentTexts::LANDING);
        $settings->invitation_email = $request->texts('invitation', ContentTexts::INVITATION);
        $settings->save();

        [$old, $new] = AuditLogger::diff($before, $settings->toArray());

        if ($new !== []) {
            $audit->record('content.texts_updated', 'ContentSettings', $old, $new);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.saved')]);

        return to_route('admin.texts.edit');
    }
}
