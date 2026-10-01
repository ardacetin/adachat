<?php

use App\Domain\Attachments\Enums\AttachmentKind;
use App\Models\MessageAttachment;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->create();
});

function uploadFixture(string $name, ?string $as = null)
{
    return test()->actingAs(test()->user)->post(
        route('attachments.store'),
        ['file' => uploadedFile($as ?? $name, file_get_contents(base_path("tests/Fixtures/attachments/{$name}")))],
        ['Accept' => 'application/json'],
    );
}

function zipWith(array $files, int $level = 6): string
{
    $path = tempnam(sys_get_temp_dir(), 'zip');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);

    foreach ($files as $name => $contents) {
        $zip->addFromString($name, $contents);
        $zip->setCompressionName($name, ZipArchive::CM_DEFLATE, $level);
    }

    $zip->close();
    $bytes = (string) file_get_contents($path);
    unlink($path);

    return $bytes;
}

test('PDF text and pages are extracted on upload', function () {
    $response = uploadFixture('sample.pdf')->assertCreated();
    $attachment = MessageAttachment::query()->sole();

    expect($attachment->kind)->toBe(AttachmentKind::Pdf)
        ->and($attachment->page_count)->toBe(2)
        ->and($attachment->extracted_text)->toContain('Ada belge testi: bütçe raporu')->toContain('Ikinci sayfa: özet ve sonuç')
        // Two pages sent natively cost more than their short text.
        ->and($attachment->token_estimate)->toBe(3000)
        ->and($response->json('pages'))->toBe(2);
});

test('a scanned PDF is accepted without text', function () {
    uploadFixture('scanned.pdf')->assertCreated();

    expect(MessageAttachment::query()->sole()->extracted_text)->toBe('');
});

test('Word, Excel and PowerPoint text is extracted', function (string $file, string $mime, array $expected, ?int $pages) {
    uploadFixture($file)->assertCreated();
    $attachment = MessageAttachment::query()->sole();

    expect($attachment->kind)->toBe(AttachmentKind::Document)
        ->and($attachment->mime)->toBe($mime)
        ->and($attachment->page_count)->toBe($pages)
        ->and($attachment->token_estimate)->toBe((int) ceil(strlen($attachment->extracted_text) / 2));

    foreach ($expected as $line) {
        expect($attachment->extracted_text)->toContain($line);
    }
})->with([
    'docx' => ['sample.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', ['Toplantı notları', "Karar: bütçe onaylandı\t(oy birliği)"], null],
    'xlsx' => ['sample.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', ["## Bütçe\nBirim\tTutar\nFizik\t1250.5\nKimya\t\tnot", "## Özet\nToplam\t1250.5"], null],
    'pptx' => ['sample.pptx', 'application/vnd.openxmlformats-officedocument.presentationml.presentation', ["## Slide 1\nProje sunumu", "## Slide 2\nİkinci slayt\nHedefler", "## Slide 3\nOnuncu"], 3],
]);

test('the type comes from the parts, not the extension', function () {
    // An Office file renamed to .zip is still read; a plain zip named .docx is not.
    uploadFixture('sample.docx', 'notes.zip')->assertCreated();
    expect(MessageAttachment::query()->sole()->kind)->toBe(AttachmentKind::Document);

    test()->actingAs(test()->user)
        ->post(route('attachments.store'), ['file' => uploadedFile('fake.docx', zipWith(['readme.txt' => 'hi']))], ['Accept' => 'application/json'])
        ->assertJsonValidationErrors(['file' => 'not supported']);
});

test('long text is cut with a note for the model', function () {
    config(['ada.attachments.max_text_chars' => 20]);

    uploadFixture('sample.docx')->assertCreated();

    expect(MessageAttachment::query()->sole()->extracted_text)
        ->toBe("Toplantı notları\nKar\n\n[… cut: only the first 20 characters of this file]");
});

test('zip bombs, entity tricks and broken files are refused', function (Closure $contents) {
    test()->actingAs(test()->user)
        ->post(route('attachments.store'), ['file' => uploadedFile('evil.docx', $contents())], ['Accept' => 'application/json'])
        ->assertJsonValidationErrors(['file' => 'could not be read']);

    expect(MessageAttachment::query()->count())->toBe(0);
})->with([
    'zip bomb' => [fn () => zipWith([
        '[Content_Types].xml' => '<Types/>',
        'word/document.xml' => '<w:document xmlns:w="w"/>',
        'word/media/padding.bin' => str_repeat("\0", 101 * 1024 * 1024),
    ], 9)],
    'external entity' => [fn () => zipWith([
        '[Content_Types].xml' => '<Types/>',
        'word/document.xml' => '<?xml version="1.0"?><!DOCTYPE d [<!ENTITY x SYSTEM "file:///etc/passwd">]><w:document xmlns:w="w"><w:body><w:p><w:r><w:t>&x;</w:t></w:r></w:p></w:body></w:document>',
    ])],
    'billion laughs' => [fn () => zipWith([
        '[Content_Types].xml' => '<Types/>',
        'word/document.xml' => '<?xml version="1.0"?><!DOCTYPE d [<!ENTITY a "aaaaaaaaaa"><!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;&a;&a;">]><w:document xmlns:w="w"><w:t>&b;</w:t></w:document>',
    ])],
]);

test('a damaged PDF is refused', function () {
    test()->actingAs(test()->user)
        ->post(route('attachments.store'), ['file' => uploadedFile('broken.pdf', "%PDF-1.4\n%garbage\n1 0 obj << /Type /Catalog")], ['Accept' => 'application/json'])
        ->assertJsonValidationErrors(['file' => 'could not be read']);
});

test('documents have their own size limit', function () {
    config(['ada.attachments.max_document_mb' => 0.0005]);

    uploadFixture('sample.pdf')->assertJsonValidationErrors(['file' => 'too large']);
});
