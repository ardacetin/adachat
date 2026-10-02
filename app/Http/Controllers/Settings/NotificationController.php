<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Institution\Settings\InstitutionSettings;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The user's own e-mail preferences: budget alerts at 80 % and 100 %.
 */
class NotificationController extends Controller
{
    public function edit(Request $request, InstitutionSettings $institution): Response
    {
        return Inertia::render('settings/notifications', [
            'budgetEmails' => $this->user($request)->budget_emails,
            // When the institution sends none, the switch has no effect.
            'budgetEmailsOffered' => $institution->user_budget_emails,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate(['budget_emails' => ['required', 'boolean']]);

        $user = $this->user($request);
        $user->budget_emails = (bool) $validated['budget_emails'];
        $user->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('settings.notifications_updated')]);

        return to_route('notifications.edit');
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
