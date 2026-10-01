<?php

namespace App\Http\Controllers\Chat;

use App\Domain\Attachments\AttachmentStore;
use App\Domain\Attachments\Enums\AttachmentKind;
use App\Domain\Attachments\Exceptions\AttachmentRejected;
use App\Http\Controllers\Controller;
use App\Models\MessageAttachment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Upload, view and remove chat attachments. Only the owner ever sees an
 * attachment: anyone else gets 404, so its existence is not revealed.
 */
class AttachmentController extends Controller
{
    public function __construct(private readonly AttachmentStore $store) {}

    public function store(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file']]);

        /** @var UploadedFile $file */
        $file = $request->file('file');

        try {
            $attachment = $this->store->store($this->user($request), $file);
        } catch (AttachmentRejected $rejected) {
            throw ValidationException::withMessages(['file' => $rejected->userMessage()]);
        }

        return response()->json($attachment->toClient(), 201);
    }

    public function show(Request $request, MessageAttachment $attachment): Response
    {
        $this->authorizeOwner($request, $attachment);

        // Text is always served as plain text and downloaded: whatever the
        // file contains (HTML, SVG…), the browser never renders it.
        $image = $attachment->kind === AttachmentKind::Image;

        return response($this->store->contents($attachment), 200, [
            'Content-Type' => $image ? $attachment->mime : 'text/plain; charset=UTF-8',
            'Content-Disposition' => ($image ? 'inline' : 'attachment').'; filename*=UTF-8\'\''.rawurlencode($attachment->original_name),
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Request $request, MessageAttachment $attachment): Response
    {
        $this->authorizeOwner($request, $attachment);
        abort_unless($attachment->isPending(), 409);

        $this->store->delete($attachment);

        return response()->noContent();
    }

    private function authorizeOwner(Request $request, MessageAttachment $attachment): void
    {
        abort_unless($attachment->user_id === $this->user($request)->id, 404);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
