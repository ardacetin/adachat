<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logs out users who were disabled after they signed in, and ends sessions
 * older than the absolute maximum (ada.auth.max_session_minutes) however
 * active they are; SESSION_LIFETIME only limits idle time.
 */
class EnsureUserIsActive
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        if (! $user->isActive()) {
            return $this->signOut($request, 'account_disabled');
        }

        $signedInAt = $request->session()->get(self::SIGNED_IN_AT);

        if (! is_int($signedInAt)) {
            // Sessions from before this check start counting now.
            self::startSession($request);
        } elseif (now()->getTimestamp() - $signedInAt > (int) config('ada.auth.max_session_minutes') * 60) {
            return $this->signOut($request, 'session_expired');
        }

        return $next($request);
    }

    private const SIGNED_IN_AT = 'ada.signed_in_at';

    /**
     * Call right after a sign-in.
     */
    public static function startSession(Request $request): void
    {
        $request->session()->put(self::SIGNED_IN_AT, now()->getTimestamp());
    }

    private function signOut(Request $request, string $reason): Response
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(['code' => $reason], 401);
        }

        return redirect()->route('login')->withErrors([
            'auth' => __('auth.errors.'.$reason),
        ]);
    }
}
