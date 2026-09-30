<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    /**
     * Show the sign-in page.
     */
    public function show(): Response
    {
        return Inertia::render('auth/login', [
            'devLoginUsers' => DevLoginController::isEnabled()
                ? User::query()
                    ->orderBy('name')
                    ->limit(20)
                    ->get(['id', 'name', 'email', 'role'])
                : null,
        ]);
    }

    /**
     * Sign the current user out.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
