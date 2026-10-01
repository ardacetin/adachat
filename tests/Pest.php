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

// Concurrency tests commit real data for separate worker processes, so they
// cannot run inside RefreshDatabase's transaction. They rebuild the schema
// before and after themselves instead.
pest()->extend(TestCase::class)->in('Concurrency');

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

require_once __DIR__.'/Support/attachments.php';
