<?php

namespace App\Domain\Attachments;

use App\Domain\Attachments\Enums\AttachmentKind;
use App\Domain\Attachments\Exceptions\AttachmentRejected;
use App\Domain\Attachments\Extractors\ExtractedText;
use App\Domain\Attachments\Extractors\OfficeExtractor;
use App\Domain\Attachments\Extractors\PdfExtractor;
use App\Models\MessageAttachment;
use App\Models\User;
use finfo;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Accepts, stores and deletes chat attachments.
 *
 * The type is decided from the file's content (finfo), never from its name
 * or the browser's claim. Files live on the private disk under
 * attachments/{user}/{uuid}; only their owner can read them back
 * (AttachmentController).
 */
final class AttachmentStore
{
    public const DIRECTORY = 'attachments';

    /** Image types every supported provider accepts. No SVG (scriptable). */
    private const IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

    /** Text-like types finfo reports outside text/*. */
    private const TEXT_TYPES = [
        'application/json', 'application/xml', 'application/javascript', 'application/x-javascript',
        'application/x-yaml', 'application/yaml', 'application/x-ndjson', 'application/sql',
        'application/x-sh', 'application/x-httpd-php', 'application/x-tex',
    ];

    /** The types Office files are stored with, whatever finfo reported. */
    private const OFFICE_TYPES = [
        OfficeExtractor::WORD => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        OfficeExtractor::EXCEL => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        OfficeExtractor::POWERPOINT => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];

    /** Bytes per token in the conservative estimate (as EstimatedInputTokenCounter). */
    private const BYTES_PER_TOKEN = 2;

    public function __construct(
        private readonly PdfExtractor $pdf,
        private readonly OfficeExtractor $office,
    ) {}

    public static function disk(): Filesystem
    {
        return Storage::disk('local');
    }

    /**
     * @throws AttachmentRejected
     */
    public function store(User $user, UploadedFile $file): MessageAttachment
    {
        $pending = MessageAttachment::query()->where('user_id', $user->id)->pending()->count();

        if ($pending >= (int) config('ada.attachments.max_pending')) {
            throw new AttachmentRejected('too_many_pending');
        }

        $path = (string) $file->getRealPath();
        $mime = $this->detectMime($path);
        $size = (int) $file->getSize();

        // finfo may report an Office file as a plain archive; its parts decide.
        $office = in_array($mime, ['application/zip', 'application/octet-stream', ...array_values(self::OFFICE_TYPES)], true)
            ? OfficeExtractor::detect($path)
            : null;

        if ($office !== null) {
            $mime = self::OFFICE_TYPES[$office];
        }

        $attributes = match (true) {
            in_array($mime, self::IMAGE_TYPES, true) => $this->image($path, $mime, $size),
            $office !== null => $this->document($path, $size, $this->office->extract($path), AttachmentKind::Document),
            $mime === 'application/pdf' => $this->document($path, $size, $this->pdf->extract($path), AttachmentKind::Pdf),
            $this->isText($mime, $path) => $this->text($path, $size),
            default => throw new AttachmentRejected('unsupported_type'),
        };

        $attachment = new MessageAttachment;
        $attachment->id = (string) Str::uuid7();
        $stored = self::DIRECTORY."/{$user->id}/{$attachment->id}";

        self::disk()->putFileAs(dirname($stored), $file, basename($stored));

        $attachment->forceFill([
            ...$attributes,
            'user_id' => $user->id,
            'mime' => $mime,
            'size' => $size,
            'sha256' => (string) hash_file('sha256', $path),
            'path' => $stored,
            'original_name' => self::cleanName($file->getClientOriginalName()),
        ])->save();

        return $attachment;
    }

    public function contents(MessageAttachment $attachment): string
    {
        return (string) self::disk()->get($attachment->path);
    }

    public function delete(MessageAttachment $attachment): void
    {
        self::disk()->delete($attachment->path);
        $attachment->delete();
    }

    /**
     * Deletes the files of the attachments in $query (the rows are left to
     * the caller or to the database cascade). Returns the number of files.
     *
     * @param  Builder<MessageAttachment>  $query
     */
    public function deleteFiles(Builder $query): int
    {
        $count = 0;

        $query->select(['id', 'path'])->chunkById(500, function ($rows) use (&$count) {
            $paths = $rows->pluck('path')->all();
            self::disk()->delete($paths);
            $count += count($paths);
        });

        return $count;
    }

    /**
     * @return array{kind: AttachmentKind, token_estimate: int, width: int, height: int}
     */
    private function image(string $path, string $mime, int $size): array
    {
        $this->assertSize($size, 'max_image_mb');

        $info = @getimagesize($path);

        if ($info === false || $info['mime'] !== $mime) {
            throw new AttachmentRejected('unreadable_image');
        }

        $max = (int) config('ada.attachments.max_image_side');

        if ($info[0] > $max || $info[1] > $max) {
            throw new AttachmentRejected('image_too_large', ['max' => $max]);
        }

        return [
            'kind' => AttachmentKind::Image,
            'token_estimate' => (int) config('ada.attachments.image_tokens'),
            'width' => $info[0],
            'height' => $info[1],
        ];
    }

    /**
     * @return array{kind: AttachmentKind, extracted_text: string, token_estimate: int}
     */
    private function text(string $path, int $size): array
    {
        $this->assertSize($size, 'max_text_mb');

        $text = (string) file_get_contents($path);

        if ($text === '' || str_contains($text, "\0")) {
            throw new AttachmentRejected('unsupported_type');
        }

        // Turkish files saved by older Windows tools are Windows-1254.
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = (string) mb_convert_encoding($text, 'UTF-8', 'Windows-1254');
        }

        $text = str_replace("\r\n", "\n", (string) preg_replace('/^\xEF\xBB\xBF/', '', $text));

        return [
            'kind' => AttachmentKind::Text,
            'extracted_text' => $text,
            'token_estimate' => (int) ceil(strlen($text) / self::BYTES_PER_TOKEN),
        ];
    }

    /**
     * PDF and Office files: the text is extracted once, here, and cut to
     * ada.attachments.max_text_chars with a note for the model.
     *
     * @return array{kind: AttachmentKind, extracted_text: string, page_count: int|null, token_estimate: int}
     */
    private function document(string $path, int $size, ExtractedText $extracted, AttachmentKind $kind): array
    {
        $this->assertSize($size, 'max_document_mb');

        $limit = (int) config('ada.attachments.max_text_chars');
        $text = $extracted->text;

        if (mb_strlen($text) > $limit) {
            $text = mb_substr($text, 0, $limit)."\n\n[… cut: only the first {$limit} characters of this file]";
        }

        $estimate = (int) ceil(strlen($text) / self::BYTES_PER_TOKEN);

        // Sent natively, a PDF also costs its pages as images.
        if ($kind === AttachmentKind::Pdf) {
            $estimate = max($estimate, (int) $extracted->pages * (int) config('ada.attachments.pdf_page_tokens'));
        }

        return [
            'kind' => $kind,
            'extracted_text' => $text,
            'page_count' => $extracted->pages,
            'token_estimate' => $estimate,
        ];
    }

    private function assertSize(int $size, string $limitKey): void
    {
        $limit = (float) config("ada.attachments.{$limitKey}");

        if ($size > $limit * 1024 * 1024) {
            throw new AttachmentRejected('too_large', ['max' => rtrim(rtrim(number_format($limit, 2, '.', ''), '0'), '.')]);
        }
    }

    private function detectMime(string $path): string
    {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);

        return is_string($mime) ? strtolower($mime) : 'application/octet-stream';
    }

    private function isText(string $mime, string $path): bool
    {
        if (str_starts_with($mime, 'text/') || in_array($mime, self::TEXT_TYPES, true)) {
            return true;
        }

        // finfo cannot classify very short files; accept them when they
        // contain no control characters other than whitespace.
        if ($mime === 'application/octet-stream' && filesize($path) <= 1024) {
            return preg_match('/[\x00-\x08\x0E-\x1F]/', (string) file_get_contents($path)) === 0;
        }

        return false;
    }

    /**
     * The name as shown back to the user: no path, no control characters.
     */
    private static function cleanName(string $name): string
    {
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $name)));

        return Str::limit(trim($name) !== '' ? trim($name) : 'file', 200, '');
    }
}
