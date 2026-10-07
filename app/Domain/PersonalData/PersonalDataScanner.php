<?php

namespace App\Domain\PersonalData;

use Illuminate\Support\Facades\Log;

/**
 * Finds personal data the institution set an action for. Institution
 * patterns run with a low backtracking limit, so a pattern that would take
 * too long finds nothing instead of stalling the request.
 */
final class PersonalDataScanner
{
    public const ACTIONS = ['off', 'warn', 'mask', 'block'];

    private const PATTERN_BACKTRACK_LIMIT = 100000;

    /**
     * @param  array<string, string>  $rules  action per built-in kind
     * @param  list<array{name: string, pattern: string, action: string}>  $patterns
     */
    public function __construct(
        private readonly array $rules,
        private readonly array $patterns,
    ) {}

    public static function fromSettings(PersonalDataSettings $settings): self
    {
        return new self($settings->rules, $settings->patterns);
    }

    /**
     * The action for a kind: a built-in kind or an institution pattern's name.
     */
    public function action(string $kind): string
    {
        if (in_array($kind, Detectors::KINDS, true)) {
            return $this->rules[$kind] ?? 'off';
        }

        foreach ($this->patterns as $pattern) {
            if ($pattern['name'] === $kind) {
                return $pattern['action'];
            }
        }

        return 'off';
    }

    public function enabled(): bool
    {
        return $this->kinds() !== [];
    }

    /**
     * Whether anything is masked: documents are then sent as text, so that
     * their text can be masked too.
     */
    public function masks(): bool
    {
        return in_array('mask', array_map($this->action(...), $this->kinds()), true);
    }

    /**
     * Non-overlapping detections in text order; where two overlap, the one
     * that starts first (then the longer one) wins.
     *
     * @param  list<string>|null  $actions  only kinds with these actions; null: every kind that is not off
     * @return list<Detection>
     */
    public function scan(string $text, ?array $actions = null): array
    {
        if ($text === '') {
            return [];
        }

        $found = [];

        foreach ($this->kinds() as $kind) {
            if ($actions !== null && ! in_array($this->action($kind), $actions, true)) {
                continue;
            }

            array_push($found, ...(in_array($kind, Detectors::KINDS, true) ? Detectors::find($kind, $text) : $this->custom($kind, $text)));
        }

        usort($found, fn (Detection $a, Detection $b): int => [$a->offset, -strlen($a->value)] <=> [$b->offset, -strlen($b->value)]);
        $kept = [];
        $end = -1;

        foreach ($found as $detection) {
            if ($detection->offset >= $end) {
                $kept[] = $detection;
                $end = $detection->end();
            }
        }

        return $kept;
    }

    /**
     * Whether an institution pattern compiles.
     */
    public static function validPattern(string $pattern): bool
    {
        return @preg_match(self::delimited($pattern), '') !== false;
    }

    /**
     * @return list<string>
     */
    private function kinds(): array
    {
        $kinds = array_values(array_filter(Detectors::KINDS, fn (string $kind): bool => ($this->rules[$kind] ?? 'off') !== 'off'));

        foreach ($this->patterns as $pattern) {
            if ($pattern['action'] !== 'off') {
                $kinds[] = $pattern['name'];
            }
        }

        return $kinds;
    }

    /**
     * @return list<Detection>
     */
    private function custom(string $kind, string $text): array
    {
        $pattern = collect($this->patterns)->firstWhere('name', $kind)['pattern'] ?? null;

        if (! is_string($pattern)) {
            return [];
        }

        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', (string) self::PATTERN_BACKTRACK_LIMIT);

        try {
            $count = @preg_match_all(self::delimited($pattern), $text, $found, PREG_OFFSET_CAPTURE);
        } finally {
            ini_set('pcre.backtrack_limit', (string) $limit);
        }

        if ($count === false) {
            Log::warning('A personal data pattern failed and was skipped.', ['pattern' => $kind, 'error' => preg_last_error_msg()]);

            return [];
        }

        $detections = [];

        foreach ($found[0] as [$value, $offset]) {
            if ($value !== '') {
                $detections[] = new Detection($kind, $offset, $value);
            }
        }

        return $detections;
    }

    private static function delimited(string $pattern): string
    {
        // A control character as delimiter: patterns never need escaping for it.
        return "\x01".$pattern."\x01u";
    }
}
