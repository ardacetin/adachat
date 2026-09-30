<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\LaravelSettings\Settings;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests run against MySQL (never SQLite): locking semantics,
| DECIMAL behaviour and collations must match production.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/**
 * Persist settings values for a test (e.g. updateSettings(AuthSettings::class, [...])).
 *
 * @param  class-string<Settings>  $class
 * @param  array<string, mixed>  $values
 */
function updateSettings(string $class, array $values): void
{
    app($class)->fill($values)->save();
}
