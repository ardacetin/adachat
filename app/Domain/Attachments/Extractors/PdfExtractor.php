<?php

namespace App\Domain\Attachments\Extractors;

use App\Domain\Attachments\Exceptions\AttachmentRejected;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Text and page count of a PDF (smalot/pdfparser, pure PHP). A scanned
 * PDF yields no text; it can still be sent to models that read PDFs.
 */
final class PdfExtractor
{
    /** Bytes one decoded stream may take. */
    private const DECODE_MEMORY_LIMIT = 32 * 1024 * 1024;

    /**
     * @throws AttachmentRejected
     */
    public function extract(string $path): ExtractedText
    {
        $config = new Config;
        $config->setRetainImageContent(false);
        // A small, highly compressed stream can inflate past PHP's memory
        // limit, which no catch can recover from: such a PDF is refused.
        $config->setDecodeMemoryLimit(self::DECODE_MEMORY_LIMIT);

        try {
            $document = (new Parser([], $config))->parseFile($path);

            return new ExtractedText(
                trim((string) preg_replace("/[ \t]+\n/", "\n", $document->getText())),
                count($document->getPages()),
            );
        } catch (Throwable) {
            // Encrypted, damaged or unsupported PDFs.
            throw new AttachmentRejected('unreadable_document');
        }
    }
}
