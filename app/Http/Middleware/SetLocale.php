<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the UI locale: user preference → institution default →
 * browser Accept-Language → fallback locale.
 */
class SetLocale
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale($this->resolve($request));

        return $next($request);
    }

    public function resolve(Request $request): string
    {
        /** @var list<string> $available */
        $available = config('ada.locales.available');

        $candidates = [
            $request->user()?->locale,
            config('ada.locales.default'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && in_array($candidate, $available, true)) {
                return $candidate;
            }
        }

        return $request->getPreferredLanguage($available)
            ?? (string) config('ada.locales.fallback');
    }
}
