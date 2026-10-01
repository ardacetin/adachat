<?php

namespace App\Domain\Attachments\Extractors;

use App\Domain\Attachments\Exceptions\AttachmentRejected;
use XMLReader;
use ZipArchive;

/**
 * Text of Word, Excel and PowerPoint files (Office Open XML), read with
 * ZipArchive and XMLReader only.
 *
 * Untrusted archives: the number of entries and the uncompressed sizes are
 * checked before anything is read (zip bombs), and XML with a document type
 * declaration is refused, so no entity is ever expanded (XXE, billion
 * laughs). Legacy binary formats (.doc, .xls, .ppt) are not supported.
 */
final class OfficeExtractor
{
    public const WORD = 'docx';

    public const EXCEL = 'xlsx';

    public const POWERPOINT = 'pptx';

    private const MAX_ENTRIES = 2000;

    private const MAX_TOTAL_BYTES = 100 * 1024 * 1024;

    private const MAX_PART_BYTES = 20 * 1024 * 1024;

    /**
     * Which Office format the archive holds, from its parts; null if none.
     */
    public static function detect(string $path): ?string
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return null;
        }

        try {
            if ($zip->locateName('[Content_Types].xml') === false) {
                return null;
            }

            return match (true) {
                $zip->locateName('word/document.xml') !== false => self::WORD,
                $zip->locateName('xl/workbook.xml') !== false => self::EXCEL,
                $zip->locateName('ppt/presentation.xml') !== false => self::POWERPOINT,
                default => null,
            };
        } finally {
            $zip->close();
        }
    }

    /**
     * @throws AttachmentRejected
     */
    public function extract(string $path): ExtractedText
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new AttachmentRejected('unreadable_document');
        }

        try {
            $this->assertSafe($zip);

            return match (self::detect($path)) {
                self::WORD => new ExtractedText($this->word($zip)),
                self::EXCEL => new ExtractedText($this->excel($zip)),
                self::POWERPOINT => $this->powerPoint($zip),
                default => throw new AttachmentRejected('unsupported_type'),
            };
        } finally {
            $zip->close();
        }
    }

    private function assertSafe(ZipArchive $zip): void
    {
        if ($zip->numFiles > self::MAX_ENTRIES) {
            throw new AttachmentRejected('unreadable_document');
        }

        $total = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $total += $stat === false ? 0 : $stat['size'];

            if ($total > self::MAX_TOTAL_BYTES) {
                throw new AttachmentRejected('unreadable_document');
            }
        }
    }

    private function word(ZipArchive $zip): string
    {
        $text = '';
        $reader = $this->reader($zip, 'word/document.xml');

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT) {
                $text .= match ($reader->name) {
                    'w:t' => $reader->readString(),
                    'w:tab' => "\t",
                    'w:br', 'w:cr' => "\n",
                    default => '',
                };
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT && $reader->name === 'w:p') {
                $text .= "\n";
            }
        }

        return self::tidy($text);
    }

    private function excel(ZipArchive $zip): string
    {
        $shared = [];

        if ($zip->locateName('xl/sharedStrings.xml') !== false) {
            $reader = $this->reader($zip, 'xl/sharedStrings.xml');
            $current = null;

            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'si') {
                    $current = '';
                } elseif ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 't' && $current !== null) {
                    $current .= $reader->readString();
                } elseif ($reader->nodeType === XMLReader::END_ELEMENT && $reader->name === 'si') {
                    $shared[] = (string) $current;
                    $current = null;
                }
            }
        }

        $targets = $this->relationships($zip, 'xl/_rels/workbook.xml.rels');
        $maxRows = (int) config('ada.attachments.max_sheet_rows', 2000);
        $out = [];
        $reader = $this->reader($zip, 'xl/workbook.xml');

        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'sheet') {
                continue;
            }

            $name = (string) $reader->getAttribute('name');
            $target = $targets[(string) $reader->getAttribute('r:id')] ?? null;
            $part = $target === null ? null : 'xl/'.ltrim(str_replace('/xl/', '', $target), '/');

            if ($part === null || $zip->locateName($part) === false) {
                continue;
            }

            $out[] = "## {$name}\n".$this->sheet($zip, $part, $shared, $maxRows);
        }

        return self::tidy(implode("\n\n", $out));
    }

    /**
     * Rows as tab-separated values, cells in column order.
     *
     * @param  list<string>  $shared
     */
    private function sheet(ZipArchive $zip, string $part, array $shared, int $maxRows): string
    {
        $reader = $this->reader($zip, $part);
        $rows = [];
        $row = [];
        $cell = null;
        $type = null;
        $count = 0;

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT) {
                if ($reader->name === 'row') {
                    $row = [];
                } elseif ($reader->name === 'c') {
                    $cell = self::column((string) $reader->getAttribute('r'), count($row));
                    $type = $reader->getAttribute('t');
                } elseif ($cell !== null && ($reader->name === 'v' || ($reader->name === 't' && $type === 'inlineStr'))) {
                    $value = $reader->readString();
                    $row[$cell] = match ($type) {
                        's' => $shared[(int) $value] ?? '',
                        'b' => $value === '1' ? 'TRUE' : 'FALSE',
                        default => $value,
                    };
                }
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT && $reader->name === 'row') {
                if (++$count > $maxRows) {
                    $rows[] = "[… only the first {$maxRows} rows]";

                    break;
                }

                if ($row !== []) {
                    $line = array_fill(0, max(array_keys($row)) + 1, '');
                    $rows[] = rtrim(implode("\t", array_replace($line, $row)));
                }
            }
        }

        return implode("\n", $rows);
    }

    private function powerPoint(ZipArchive $zip): ExtractedText
    {
        $slides = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);

            if (preg_match('#^ppt/slides/slide(\d+)\.xml$#', $name, $match) === 1) {
                $slides[(int) $match[1]] = $name;
            }
        }

        ksort($slides);
        $out = [];
        $number = 0;

        foreach ($slides as $part) {
            $text = '';
            $reader = $this->reader($zip, $part);

            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'a:t') {
                    $text .= $reader->readString();
                } elseif ($reader->nodeType === XMLReader::END_ELEMENT && $reader->name === 'a:p') {
                    $text .= "\n";
                }
            }

            $out[] = '## Slide '.(++$number)."\n".trim($text);
        }

        return new ExtractedText(self::tidy(implode("\n\n", $out)), count($slides));
    }

    /**
     * @return array<string, string> relationship id → target
     */
    private function relationships(ZipArchive $zip, string $part): array
    {
        if ($zip->locateName($part) === false) {
            return [];
        }

        $targets = [];
        $reader = $this->reader($zip, $part);

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'Relationship') {
                $targets[(string) $reader->getAttribute('Id')] = (string) $reader->getAttribute('Target');
            }
        }

        return $targets;
    }

    /**
     * @throws AttachmentRejected
     */
    private function reader(ZipArchive $zip, string $part): XMLReader
    {
        $stat = $zip->statName($part);

        if ($stat === false || $stat['size'] > self::MAX_PART_BYTES) {
            throw new AttachmentRejected('unreadable_document');
        }

        $xml = $zip->getFromName($part);

        // No document type declarations: nothing to expand or fetch.
        if (! is_string($xml) || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new AttachmentRejected('unreadable_document');
        }

        $reader = XMLReader::XML($xml, null, LIBXML_NONET | LIBXML_COMPACT);

        if (! $reader instanceof XMLReader) {
            throw new AttachmentRejected('unreadable_document');
        }

        return $reader;
    }

    /**
     * Zero-based column index from a cell reference such as "C12".
     */
    private static function column(string $reference, int $fallback): int
    {
        if (preg_match('/^([A-Z]+)/', $reference, $match) !== 1) {
            return $fallback;
        }

        $index = 0;

        foreach (str_split($match[1]) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index - 1;
    }

    private static function tidy(string $text): string
    {
        return trim((string) preg_replace("/\n{3,}/", "\n\n", str_replace("\r", '', $text)));
    }
}
