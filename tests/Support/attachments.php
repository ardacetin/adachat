<?php

use Illuminate\Http\UploadedFile;

/**
 * A real (black, greyscale) PNG of the given size, built without GD.
 */
function pngBytes(int $width = 2, int $height = 2): string
{
    $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    $rows = str_repeat("\0".str_repeat("\0", $width), $height);

    return "\x89PNG\r\n\x1a\n"
        .$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 0, 0, 0, 0))
        .$chunk('IDAT', (string) gzcompress($rows))
        .$chunk('IEND', '');
}

function uploadedFile(string $name, string $contents): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $contents);
}
