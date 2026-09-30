<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Identity\Enums\Appearance;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AppearanceController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'appearance' => ['required', Rule::enum(Appearance::class)],
        ]);

        $user = $request->user();
        abort_if($user === null, 403);

        $user->appearance = Appearance::from($validated['appearance']);
        $user->save();

        return back();
    }
}
