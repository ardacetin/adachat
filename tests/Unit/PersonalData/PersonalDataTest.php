<?php

use App\Domain\AI\Data\ChatMessage;
use App\Domain\AI\Data\ChatRequest;
use App\Domain\PersonalData\Detectors;
use App\Domain\PersonalData\Masker;
use App\Domain\PersonalData\PersonalDataScanner;

function scanner(string $action = 'mask', array $patterns = []): PersonalDataScanner
{
    return new PersonalDataScanner(array_fill_keys(Detectors::KINDS, $action), $patterns);
}

/**
 * @return list<string>
 */
function found(string $kind, string $text): array
{
    return array_map(fn ($detection) => $detection->value, Detectors::find($kind, $text));
}

test('Turkish identity numbers need valid check digits', function () {
    expect(found('tckn', 'no 10000000146 ve 12345678950'))->toBe(['10000000146', '12345678950'])
        ->and(found('tckn', '12345678901 01234567890 100000001460'))->toBe([]);
});

test('IBANs are found with or without spaces, not running into the next word', function () {
    expect(found('iban', 'TR33 0006 1005 1978 6457 8413 26 hesabına'))->toBe(['TR33 0006 1005 1978 6457 8413 26'])
        ->and(found('iban', 'IBAN: TR330006100519786457841326.'))->toBe(['TR330006100519786457841326'])
        ->and(found('iban', 'DE89370400440532013000'))->toBe(['DE89370400440532013000'])
        ->and(found('iban', 'TR33 0006 1005 1978 6457 8413 27'))->toBe([]);
});

test('card numbers need a valid Luhn digit', function () {
    expect(found('card', 'kart 4111 1111 1111 1111 ve 5500-0000-0000-0004'))->toBe(['4111 1111 1111 1111', '5500-0000-0000-0004'])
        ->and(found('card', 'sipariş 4111111111111112'))->toBe([]);
});

test('Turkish phone numbers are found in the usual forms', function () {
    expect(found('phone', '0532 123 45 67, +90 532 123 4567, (0212) 555 12 34, 5321234567'))
        ->toBe(['0532 123 45 67', '+90 532 123 4567', '(0212) 555 12 34', '5321234567'])
        // Other numbers are not phones.
        ->and(found('phone', '2024 yılında 1234567 ve 12345678901'))->toBe([]);
});

test('e-mail addresses are found', function () {
    expect(found('email', 'Yaz: ada.lovelace+test@uni.edu.tr.'))->toBe(['ada.lovelace+test@uni.edu.tr']);
});

test('only kinds with an action are scanned, without overlaps', function () {
    $scanner = new PersonalDataScanner(['tckn' => 'warn', 'phone' => 'off'], [['name' => 'Öğrenci no', 'pattern' => '\b20\d{7}\b', 'action' => 'block']]);

    $found = $scanner->scan('10000000146 0532 123 45 67 201912345');

    expect(array_map(fn ($d) => [$d->kind, $d->value], $found))->toBe([['tckn', '10000000146'], ['Öğrenci no', '201912345']])
        ->and($scanner->action('Öğrenci no'))->toBe('block')
        ->and($scanner->scan('10000000146', ['block']))->toBe([])
        ->and($scanner->masks())->toBeFalse();
});

test('an institution pattern that backtracks too much finds nothing instead of hanging', function () {
    $scanner = scanner('off', [['name' => 'Bad', 'pattern' => '(a+)+$', 'action' => 'mask']]);

    $started = microtime(true);
    expect($scanner->scan(str_repeat('a', 5000).'b'))->toBe([])
        ->and(microtime(true) - $started)->toBeLessThan(2.0)
        ->and(PersonalDataScanner::validPattern('(unclosed'))->toBeFalse()
        ->and(PersonalDataScanner::validPattern('\b20\d{7}\b'))->toBeTrue();
});

test('the same value gets the same placeholder in every message of a request', function () {
    $masker = new Masker(scanner());
    $request = new ChatRequest('m', [
        ChatMessage::user('Benim numaram 10000000146, e-postam ada@uni.edu.tr.'),
        ChatMessage::assistant('10000000146 numaralı kaydı buldum.'),
        ChatMessage::user('Peki 12345678950 ve 10000000146?'),
    ], 100, 'Kurum kuralları.');

    $masked = $masker->request($request);

    expect(array_map(fn ($message) => $message->text, $masked->messages))->toBe([
        'Benim numaram [TCKN_1], e-postam [EMAIL_1].',
        '[TCKN_1] numaralı kaydı buldum.',
        'Peki [TCKN_2] ve [TCKN_1]?',
    ])->and($masked->systemPrompt)->toStartWith("Kurum kuralları.\n\nSome personal data")
        ->and($masker->unmask('[TCKN_2] ve [EMAIL_1]'))->toBe('12345678950 ve ada@uni.edu.tr')
        ->and($masker->counts('10000000146 ve 10000000146'))->toBe(['tckn' => 2]);

    // Nothing to mask: the request is unchanged, without the note.
    expect((new Masker(scanner()))->request(new ChatRequest('m', [ChatMessage::user('Merhaba')], 100)))->toEqual(new ChatRequest('m', [ChatMessage::user('Merhaba')], 100));
});

test('placeholders split over streamed chunks are put back', function () {
    $masker = new Masker(scanner());
    $masker->mask('10000000146 ada@uni.edu.tr');
    $stream = $masker->stream();

    $out = $stream->push('Numara [TC').$stream->push('KN_1], ad').$stream->push('res [EMAIL_').$stream->push('1]. [not a placeholder').$stream->flush();

    expect($out)->toBe('Numara 10000000146, adres ada@uni.edu.tr. [not a placeholder');
});
