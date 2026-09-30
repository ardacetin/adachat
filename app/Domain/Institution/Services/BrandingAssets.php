<?php

namespace App\Domain\Institution\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stores uploaded logos and favicons on the public disk under unguessable
 * names. Only raster formats are accepted (validated by the request); SVG
 * would need sanitising first and is not supported yet.
 */
final class BrandingAssets
{
    public const DIRECTORY = 'branding';

    public function store(UploadedFile $file, string $kind, ?string $previousPath): string
    {
        $path = $file->storeAs(
            self::DIRECTORY,
            $kind.'-'.Str::random(24).'.'.$file->guessExtension(),
            ['disk' => 'public'],
        );

        $this->delete($previousPath);

        return (string) $path;
    }

    public function delete(?string $path): void
    {
        if ($path !== null && str_starts_with($path, self::DIRECTORY.'/')) {
            Storage::disk('public')->delete($path);
        }
    }
}
