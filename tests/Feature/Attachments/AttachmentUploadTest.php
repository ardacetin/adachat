<?php

use App\Domain\Attachments\Enums\AttachmentKind;
use App\Models\MessageAttachment;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->create();
});

function upload(string $name, string $contents)
{
    return test()->actingAs(test()->user)
        ->post(route('attachments.store'), ['file' => uploadedFile($name, $contents)], ['Accept' => 'application/json']);
}

test('an image is stored privately and described to the browser', function () {
    $response = upload('çizim.png', pngBytes(40, 30))->assertCreated();

    $attachment = MessageAttachment::query()->sole();

    expect($attachment->kind)->toBe(AttachmentKind::Image)
        ->and($attachment->mime)->toBe('image/png')
        ->and([$attachment->width, $attachment->height])->toBe([40, 30])
        ->and($attachment->token_estimate)->toBe(1600)
        ->and($attachment->isPending())->toBeTrue()
        ->and($attachment->path)->toBe("attachments/{$this->user->id}/{$attachment->id}")
        ->and($response->json())->toMatchArray(['id' => $attachment->id, 'kind' => 'image', 'name' => 'çizim.png'])
        ->and($response->json())->not->toHaveKeys(['path', 'sha256']);

    Storage::disk('local')->assertExists($attachment->path);
});

test('text files are stored with their UTF-8 text', function () {
    upload('notlar.txt', mb_convert_encoding("Şehir ve ılık rüzgâr\r\n", 'Windows-1254', 'UTF-8'))->assertCreated();
    upload('script.py', "print('merhaba')\n")->assertCreated();

    [$windows, $python] = MessageAttachment::query()->orderBy('id')->get()->all();

    expect($windows->kind)->toBe(AttachmentKind::Text)
        ->and($windows->extracted_text)->toBe("Şehir ve ılık rüzgâr\n")
        ->and($python->extracted_text)->toBe("print('merhaba')\n")
        ->and($python->token_estimate)->toBe(9);
});

test('the type comes from the content, not the name', function (string $name, string $contents, string $error) {
    upload($name, $contents)->assertUnprocessable()->assertJsonValidationErrors(['file' => $error]);

    expect(MessageAttachment::query()->count())->toBe(0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'svg' => ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'not supported'],
    'binary named .txt' => ['data.txt', "\x7FELF\x02\x01\x01\0\0\0\0\0", 'not supported'],
    'zip' => ['archive.zip', "PK\x03\x04".str_repeat("\0", 30), 'not supported'],
    'legacy Word' => ['old.doc', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\0", 504), 'not supported'],
]);

test('HTML named .png is kept as text and never rendered', function () {
    upload('photo.png', '<html><body><script>alert(1)</script></body></html>')->assertCreated();

    $attachment = MessageAttachment::query()->sole();
    expect($attachment->kind)->toBe(AttachmentKind::Text);

    $this->get(route('attachments.show', $attachment))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Disposition', "attachment; filename*=UTF-8''photo.png");
});

test('size and dimension limits are enforced', function () {
    config(['ada.attachments.max_text_mb' => 0.01]);
    upload('big.txt', str_repeat('a', 20000))->assertJsonValidationErrors(['file' => 'at most 0.01 MB']);

    upload('wide.png', pngBytes(8001, 1))->assertJsonValidationErrors(['file' => 'at most 8000 pixels']);
});

test('unsent attachments per user are limited', function () {
    config(['ada.attachments.max_pending' => 2]);

    upload('a.txt', 'a')->assertCreated();
    upload('b.txt', 'b')->assertCreated();
    upload('c.txt', 'c')->assertJsonValidationErrors(['file' => 'Too many unsent files']);
});

test('only the owner can see or remove an attachment', function () {
    upload('a.png', pngBytes())->assertCreated();
    $attachment = MessageAttachment::query()->sole();

    $this->get(route('attachments.show', $attachment))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('Cache-Control', 'max-age=3600, private');

    $other = User::factory()->create();
    $this->actingAs($other)->get(route('attachments.show', $attachment))->assertNotFound();
    $this->actingAs($other)->delete(route('attachments.destroy', $attachment))->assertNotFound();

    $this->actingAs($this->user)->delete(route('attachments.destroy', $attachment))->assertNoContent();

    expect(MessageAttachment::query()->count())->toBe(0);
    Storage::disk('local')->assertMissing($attachment->path);
});

test('guests cannot upload', function () {
    $this->post(route('attachments.store'), ['file' => uploadedFile('a.txt', 'a')])->assertRedirect(route('login'));
});
