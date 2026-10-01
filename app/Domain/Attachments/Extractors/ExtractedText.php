<?php

namespace App\Domain\Attachments\Extractors;

final readonly class ExtractedText
{
    /**
     * @param  int|null  $pages  pages (PDF) or slides (PowerPoint)
     */
    public function __construct(
        public string $text,
        public ?int $pages = null,
    ) {}
}
