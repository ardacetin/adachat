<?php

namespace App\Http\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records when a signed-in user was last seen (the users screen), at most
 * once every few minutes so that it costs one write per session and not one
 * per request.
 */
class TrackLastActivity
{
    private const INTERVAL_SECONDS = 300;

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $now = now();

        // Raw value: a model created during this request has no such attribute yet.
        $last = $user?->getAttributes()['last_active_at'] ?? null;

        if ($user !== null && ($last === null || Carbon::parse($last)->diffInSeconds($now) >= self::INTERVAL_SECONDS)) {
            // Not a profile change: updated_at stays as it is.
            $user->newQuery()->whereKey($user->getKey())->toBase()->update(['last_active_at' => $now]);
            $user->setRawAttributes([...$user->getAttributes(), 'last_active_at' => $now->toDateTimeString()], sync: true);
        }

        return $next($request);
    }
}
