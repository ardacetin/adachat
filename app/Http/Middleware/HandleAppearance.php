<?php

namespace App\Http\Middleware;

use App\Domain\Identity\Enums\Appearance;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves light/dark/system for the first paint: the signed-in user's saved
 * preference (follows them across devices), otherwise this browser's cookie.
 */
class HandleAppearance
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $cookie = $request->cookie('appearance');
        $user = $request->user();

        $appearance = $user?->appearance
            ?? (is_string($cookie) ? Appearance::tryFrom($cookie) : null)
            ?? Appearance::System;

        View::share('appearance', $appearance->value);
        View::share('appearanceFromAccount', $user !== null);

        return $next($request);
    }
}
