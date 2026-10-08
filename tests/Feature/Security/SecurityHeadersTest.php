<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

test('pages send security headers and a nonce-based script policy', function () {
    $response = $this->get(route('home'))->assertOk();

    $response->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
        ->assertHeaderMissing('Strict-Transport-Security');

    $policy = (string) $response->headers->get('Content-Security-Policy');
    preg_match("/script-src 'self' 'nonce-([^']+)'/", $policy, $match);

    expect($match)->not->toBeEmpty()
        ->and($policy)->toContain("default-src 'self'")
        ->toContain("frame-ancestors 'none'")
        ->toContain("object-src 'none'")
        ->not->toContain('unsafe-eval')
        // The inline appearance script carries the same nonce.
        ->and($response->getContent())->toContain('<script nonce="'.$match[1].'">');
});

test('HSTS is sent over https', function () {
    $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

test('every request gets a fresh nonce', function () {
    $first = $this->get(route('home'))->headers->get('Content-Security-Policy');
    $second = $this->get(route('home'))->headers->get('Content-Security-Policy');

    expect($first)->not->toBe($second);
});

test('signed-in and admin routes are rate limited per user', function () {
    expect(Route::getRoutes()->getByName('home')?->gatherMiddleware())->toContain('throttle:app')
        ->and(Route::getRoutes()->getByName('admin.index')?->gatherMiddleware())->toContain('throttle:admin')
        ->and(Route::getRoutes()->getByName('language.edit')?->gatherMiddleware())->toContain('throttle:app');

    $this->actingAs(User::factory()->create())->get(route('home'))->assertHeader('X-RateLimit-Limit', '300');
});
