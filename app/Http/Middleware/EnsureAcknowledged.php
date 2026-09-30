<?php

namespace App\Http\Middleware;

use App\Domain\Institution\Settings\PrivacySettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signed-in users acknowledge the current usage notice before anything else
 * (docs/security.md §9). They can still sign out and change the language.
 */
class EnsureAcknowledged
{
    private const ALLOWED_ROUTES = ['acknowledgment.show', 'acknowledgment.store', 'logout', 'language.edit', 'language.update'];

    public function __construct(private readonly PrivacySettings $privacy) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null
            || ! $this->privacy->acknowledgment_enabled
            || ($user->acknowledged_version ?? 0) >= $this->privacy->acknowledgment_version
            || $request->routeIs(...self::ALLOWED_ROUTES)) {
            return $next($request);
        }

        if ($request->isMethod('GET') && ! $request->expectsJson()) {
            return redirect()->guest(route('acknowledgment.show'));
        }

        return response()->json(['code' => 'acknowledgment_required'], 403);
    }
}
