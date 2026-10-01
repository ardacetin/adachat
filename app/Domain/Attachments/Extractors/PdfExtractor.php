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
    /**
     * @throws AttachmentRejected
     */
    public function extract(string $path): ExtractedText
    {
        $config = new Config;
        $config->setRetainImageContent(false);

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
