<?php

use App\Support\FrontendBuild;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->base = sys_get_temp_dir().'/ada-build-'.bin2hex(random_bytes(4));
    File::makeDirectory("{$this->base}/resources/js/routes", 0755, true);
    File::makeDirectory("{$this->base}/resources/css", 0755, true);
    File::makeDirectory("{$this->base}/public/build", 0755, true);
    file_put_contents("{$this->base}/resources/js/app.tsx", 'app');
    file_put_contents("{$this->base}/resources/css/app.css", 'css');
    file_put_contents("{$this->base}/package-lock.json", '{}');
});

afterEach(fn () => File::deleteDirectory($this->base));

function stamp(string $base): void
{
    file_put_contents("{$base}/public/build/ada-source.json", json_encode(['hash' => FrontendBuild::sourceHash($base)]));
}

test('a build is current until the front-end sources change', function () {
    expect(FrontendBuild::status($this->base, "{$this->base}/public"))->toBe('missing');

    stamp($this->base);
    expect(FrontendBuild::status($this->base, "{$this->base}/public"))->toBe('current');

    // Generated route helpers do not count; the lockfile and sources do.
    file_put_contents("{$this->base}/resources/js/routes/index.ts", 'generated');
    expect(FrontendBuild::status($this->base, "{$this->base}/public"))->toBe('current');

    file_put_contents("{$this->base}/resources/js/app.tsx", 'changed');
    expect(FrontendBuild::status($this->base, "{$this->base}/public"))->toBe('stale');
});

test('the hash matches the one the build writes', function () {
    // Same algorithm as build/source-stamp.ts: sorted paths, then
    // "path\nsha256(content)\n" per file.
    $expected = hash('sha256', implode('', array_map(
        fn (string $file) => $file."\n".hash_file('sha256', "{$this->base}/{$file}")."\n",
        ['package-lock.json', 'resources/css/app.css', 'resources/js/app.tsx'],
    )));

    expect(FrontendBuild::sourceHash($this->base))->toBe($expected);
});
