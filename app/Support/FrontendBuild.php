<?php

namespace App\Support;

use Illuminate\Support\Facades\File;

/**
 * Whether the built front end (public/build) was built from the sources in
 * this checkout. The build writes a hash of those sources to
 * public/build/ada-source.json (build/source-stamp.ts); the same hash is
 * computed here. Keep the file list in step with that plugin.
 */
final class FrontendBuild
{
    public const STAMP = 'build/ada-source.json';

    private const DIRECTORIES = ['resources/js', 'resources/css'];

    private const FILES = ['package-lock.json', 'vite.config.ts'];

    /** Route helpers generated from the PHP routes at build time. */
    private const GENERATED = ['resources/js/actions', 'resources/js/routes', 'resources/js/wayfinder'];

    /**
     * @return 'current'|'stale'|'missing'
     */
    public static function status(?string $base = null, ?string $public = null): string
    {
        $stamp = ($public ?? public_path()).'/'.self::STAMP;
        $built = is_file($stamp) ? json_decode((string) file_get_contents($stamp), true) : null;

        if (! is_array($built) || ! is_string($built['hash'] ?? null)) {
            return 'missing';
        }

        return hash_equals($built['hash'], self::sourceHash($base ?? base_path())) ? 'current' : 'stale';
    }

    public static function sourceHash(string $base): string
    {
        $files = [];

        foreach (self::DIRECTORIES as $directory) {
            if (is_dir("{$base}/{$directory}")) {
                self::walk($base, $directory, $files);
            }
        }

        foreach (self::FILES as $file) {
            if (is_file("{$base}/{$file}")) {
                $files[] = $file;
            }
        }

        sort($files, SORT_STRING);
        $hash = hash_init('sha256');

        foreach ($files as $file) {
            hash_update($hash, $file."\n".hash_file('sha256', "{$base}/{$file}")."\n");
        }

        return hash_final($hash);
    }

    /**
     * @param  list<string>  $found
     */
    private static function walk(string $base, string $directory, array &$found): void
    {
        foreach (File::directories("{$base}/{$directory}") as $path) {
            $relative = $directory.'/'.basename($path);

            if (! in_array($relative, self::GENERATED, true)) {
                self::walk($base, $relative, $found);
            }
        }

        foreach (File::files("{$base}/{$directory}", true) as $file) {
            $found[] = $directory.'/'.$file->getFilename();
        }
    }
}
