<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile. Name and e-mail come from the identity
     * provider and are therefore read-only in Ada.
     */
    public function edit(): Response
    {
        return Inertia::render('settings/profile');
    }
}
