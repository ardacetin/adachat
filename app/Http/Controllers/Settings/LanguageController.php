<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\LanguageUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class LanguageController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('settings/language');
    }

    public function update(LanguageUpdateRequest $request): RedirectResponse
    {
        $locale = $request->string('locale')->value();

        $user = $request->user();
        abort_if($user === null, 403);

        $user->locale = $locale;
        $user->save();

        app()->setLocale($locale);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('settings.language_updated')]);

        return to_route('language.edit');
    }
}
