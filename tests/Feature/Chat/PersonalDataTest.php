<?php

use App\Domain\AI\Services\CredentialVault;
use App\Domain\PersonalData\Detectors;
use App\Domain\PersonalData\PersonalDataSettings;
use App\Models\AiModel;
use App\Models\Conversation;
use App\Models\Group;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\ModelAlias;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $provider = Provider::factory()->create(['driver' => 'openai']);
    app(CredentialVault::class)->rotate($provider, 'sk-test-pii-0000');

    $model = AiModel::factory()->for($provider)->create([
        'provider_model_id' => 'gpt-pii',
        'context_window' => 200000,
        'max_output_tokens' => 8000,
        'supports_files' => true,
    ]);
    $this->alias = ModelAlias::factory()->create(['ai_model_id' => $model->id, 'max_output_tokens' => 1000]);
    $this->alias->groups()->attach(Group::default());
    $this->user = User::factory()->create();
});

/**
 * @param  array<string, string>  $rules
 */
function personalDataRules(array $rules, array $patterns = []): void
{
    updateSettings(PersonalDataSettings::class, [
        'rules' => array_merge(array_fill_keys(Detectors::KINDS, 'off'), $rules),
        'patterns' => $patterns,
    ]);
}

/**
 * The provider answers with the given text deltas.
 *
 * @param  list<string>  $deltas
 */
function fakeAnswer(array $deltas): void
{
    $sse = '';

    foreach ($deltas as $delta) {
        $sse .= "event: response.output_text.delta\ndata: ".json_encode(['type' => 'response.output_text.delta', 'delta' => $delta])."\n\n";
    }

    $sse .= "event: response.completed\ndata: ".json_encode(['type' => 'response.completed', 'response' => ['id' => 'resp_1', 'status' => 'completed', 'usage' => ['input_tokens' => 100, 'output_tokens' => 20, 'total_tokens' => 120]]])."\n\n";

    Http::fake([
        '*/responses/input_tokens' => fn () => Http::response(['input_tokens' => 100]),
        '*/responses' => fn () => Http::response($sse, 200, ['Content-Type' => 'text/event-stream']),
    ]);
}

function sendPersonal(array $payload)
{
    return test()->actingAs(test()->user)->post(route('messages.store'), [
        'model_alias_id' => test()->alias->id,
        ...$payload,
    ], ['Accept' => 'application/json']);
}

function sentToProvider(): string
{
    $request = Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/responses'))->first()[0];

    return json_encode($request->data(), JSON_UNESCAPED_UNICODE);
}

/**
 * @return string the answer text as the browser received it
 */
function streamedAnswer($response): string
{
    preg_match_all('/^event: delta\ndata: (.+)$/m', $response->streamedContent(), $matches);

    return implode('', array_map(fn ($data) => json_decode($data, true)['text'], $matches[1]));
}

test('masked values never reach the provider and come back in the answer', function () {
    personalDataRules(['tckn' => 'mask', 'email' => 'mask']);
    fakeAnswer(['Kayıt [TC', 'KN_1] için ', '[EMAIL_1] adresine yazıldı.']);

    $response = sendPersonal(['content' => 'Numaram 10000000146, e-postam ada@uni.edu.tr.'])->assertOk();

    expect(streamedAnswer($response))->toBe('Kayıt 10000000146 için ada@uni.edu.tr adresine yazıldı.');

    $sent = sentToProvider();
    expect($sent)->toContain('Numaram [TCKN_1], e-postam [EMAIL_1].')
        ->toContain('replaced with placeholders')
        ->not->toContain('10000000146')
        ->not->toContain('ada@uni.edu.tr');

    $question = Message::query()->where('role', 'user')->sole();
    expect($question->content)->toBe('Numaram 10000000146, e-postam ada@uni.edu.tr.')
        ->and($question->metadata['personal_data_masked'])->toBe(['tckn' => 1, 'email' => 1])
        ->and(Message::query()->where('role', 'assistant')->sole()->content)->toBe('Kayıt 10000000146 için ada@uni.edu.tr adresine yazıldı.');

    // The conversation shows which kinds were hidden, never the values.
    $this->get(route('conversations.show', Conversation::query()->sole()))
        ->assertInertia(fn ($page) => $page->where('messages.0.personal_data_masked', [
            ['kind' => 'Turkish identity number', 'count' => 1],
            ['kind' => 'e-mail address', 'count' => 1],
        ]));
});

test('later turns mask the history again, answers included', function () {
    personalDataRules(['tckn' => 'mask']);
    fakeAnswer(['Tamam.']);
    sendPersonal(['content' => 'Numaram 10000000146.'])->assertOk()->streamedContent();
    Message::query()->where('role', 'assistant')->sole()->forceFill(['content' => '10000000146 kaydedildi.'])->save();

    fakeAnswer(['Peki.']);
    sendPersonal(['content' => 'Teşekkürler', 'conversation_id' => Conversation::query()->value('id')])->assertOk()->streamedContent();

    expect(sentToProvider())->toContain('Numaram [TCKN_1].')
        ->toContain('[TCKN_1] kaydedildi.')
        ->not->toContain('10000000146');
});

test('warned data needs the user\'s confirmation', function () {
    personalDataRules(['iban' => 'warn']);
    fakeAnswer(['Tamam.']);

    sendPersonal(['content' => 'IBAN: TR33 0006 1005 1978 6457 8413 26'])
        ->assertUnprocessable()
        ->assertJsonPath('personal_data', ['action' => 'warn', 'kinds' => ['iban']])
        ->assertJsonPath('errors.content.0', __('chat.personal_data.warn', ['kinds' => 'IBAN']));

    expect(Message::query()->count())->toBe(0);
    Http::assertNothingSent();

    sendPersonal(['content' => 'IBAN: TR33 0006 1005 1978 6457 8413 26', 'personal_data_confirmed' => true])->assertOk()->streamedContent();
    expect(sentToProvider())->toContain('TR33 0006 1005 1978 6457 8413 26');
});

test('blocked data is refused even when confirmed, also in attachments', function () {
    personalDataRules(['card' => 'block'], [['name' => 'Öğrenci no', 'pattern' => '\b20\d{7}\b', 'action' => 'block']]);
    fakeAnswer(['Tamam.']);

    sendPersonal(['content' => 'Kart 4111 1111 1111 1111', 'personal_data_confirmed' => true])
        ->assertUnprocessable()
        ->assertJsonPath('personal_data.action', 'block');

    $this->actingAs($this->user)
        ->post(route('attachments.store'), ['file' => uploadedFile('liste.txt', "Öğrenciler:\n201912345 Ada\n")])
        ->assertCreated();

    sendPersonal(['content' => 'Özetle', 'attachment_ids' => [MessageAttachment::query()->value('id')]])
        ->assertUnprocessable()
        ->assertJsonPath('personal_data', ['action' => 'block', 'kinds' => ['Öğrenci no']]);

    Http::assertNothingSent();
});

test('while anything is masked, PDFs are sent as their text so it is masked too', function () {
    personalDataRules(['tckn' => 'mask']);
    fakeAnswer(['Tamam.']);

    $this->actingAs($this->user)
        ->post(route('attachments.store'), ['file' => uploadedFile('sample.pdf', file_get_contents(base_path('tests/Fixtures/attachments/sample.pdf')))])
        ->assertCreated();

    sendPersonal(['content' => 'Özetle', 'attachment_ids' => [MessageAttachment::query()->value('id')]])->assertOk()->streamedContent();

    expect(sentToProvider())->toContain('```sample.pdf (2 pages)')
        ->not->toContain('application/pdf');
});

test('nothing changes while every rule is off', function () {
    fakeAnswer(['Tamam.']);

    sendPersonal(['content' => 'Numaram 10000000146'])->assertOk()->streamedContent();

    expect(sentToProvider())->toContain('10000000146')->not->toContain('placeholders');
});
