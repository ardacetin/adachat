<?php

namespace App\Domain\PersonalData;

use App\Domain\AI\Data\ChatMessage;
use App\Domain\AI\Data\ChatRequest;
use Illuminate\Support\Str;

/**
 * Replaces personal data with placeholders such as [TCKN_1] in what is
 * sent to the provider, and puts the values back in the answer. One
 * masker serves one request: the same value gets the same placeholder in
 * every message of it, and only that request knows the mapping.
 */
final class Masker
{
    private const NOTE = 'Some personal data in this conversation was replaced with placeholders such as [TCKN_1] before it reached you. Keep each placeholder exactly as it is when you refer to it; it is shown to the user with the real value.';

    /** @var array<string, string> placeholder => original value */
    private array $values = [];

    /** @var array<string, string> normalised value => placeholder */
    private array $placeholders = [];

    /** @var array<string, int> */
    private array $counters = [];

    public function __construct(private readonly PersonalDataScanner $scanner) {}

    public function mask(string $text): string
    {
        $masked = '';
        $position = 0;

        foreach ($this->scanner->scan($text, ['mask']) as $detection) {
            $masked .= substr($text, $position, $detection->offset - $position).$this->placeholder($detection);
            $position = $detection->end();
        }

        return $masked.substr($text, $position);
    }

    /**
     * The request with every message masked, and a note for the model when
     * anything was.
     */
    public function request(ChatRequest $request): ChatRequest
    {
        $messages = array_map(
            fn (ChatMessage $message): ChatMessage => new ChatMessage($message->role, $this->mask($message->text), $message->parts),
            $request->messages,
        );

        if ($this->values === []) {
            return $request;
        }

        $system = trim(($request->systemPrompt ?? '')."\n\n".self::NOTE);

        return $request->withMessages($messages, $system);
    }

    /**
     * How many values of each masked kind a text holds.
     *
     * @return array<string, int>
     */
    public function counts(string $text): array
    {
        $counts = [];

        foreach ($this->scanner->scan($text, ['mask']) as $detection) {
            $counts[$detection->kind] = ($counts[$detection->kind] ?? 0) + 1;
        }

        return $counts;
    }

    public function unmask(string $text): string
    {
        return $this->values === [] ? $text : strtr($text, $this->values);
    }

    public function stream(): StreamUnmasker
    {
        return new StreamUnmasker($this->values);
    }

    public static function label(string $kind): string
    {
        if (in_array($kind, Detectors::KINDS, true)) {
            return strtoupper($kind);
        }

        $label = Str::limit(strtoupper(Str::slug($kind, '_')), 20, '');

        return $label !== '' ? $label : 'DATA';
    }

    private function placeholder(Detection $detection): string
    {
        $key = $detection->kind."\0".mb_strtolower(preg_replace('/[\s().-]/', '', $detection->value) ?? $detection->value);

        if (! isset($this->placeholders[$key])) {
            $label = self::label($detection->kind);
            $this->counters[$label] = ($this->counters[$label] ?? 0) + 1;
            $placeholder = '['.$label.'_'.$this->counters[$label].']';
            $this->placeholders[$key] = $placeholder;
            $this->values[$placeholder] = $detection->value;
        }

        return $this->placeholders[$key];
    }
}
