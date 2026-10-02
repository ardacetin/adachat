<?php

namespace App\Domain\Attachments;

use App\Domain\Attachments\Exceptions\AttachmentRejected;
use App\Models\MessageAttachment;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Accepts, stores and deletes chat attachments.
 *
 * The type is decided from the file's content (FileInspector), never from
 * its name or the browser's claim. Files live on the private disk under
 * attachments/{user}/{uuid}; only their owner can read them back
 * (AttachmentController).
 */
final class AttachmentStore
{
    public const DIRECTORY = 'attachments';

    public function __construct(private readonly FileInspector $inspector) {}

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
        $size = (int) $file->getSize();
        $attributes = $this->inspector->inspect($path, $size);

        $attachment = new MessageAttachment;
        $attachment->id = (string) Str::uuid7();
        $stored = self::DIRECTORY."/{$user->id}/{$attachment->id}";

        self::disk()->putFileAs(dirname($stored), $file, basename($stored));

        $attachment->forceFill([
            ...$attributes,
            'user_id' => $user->id,
            'size' => $size,
            'sha256' => (string) hash_file('sha256', $path),
            'path' => $stored,
            'original_name' => FileInspector::cleanName($file->getClientOriginalName()),
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
}
