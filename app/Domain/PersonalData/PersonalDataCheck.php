<?php

namespace App\Domain\PersonalData;

/**
 * Whether a new message may be sent as it is: personal data the
 * institution blocks is refused, data it warns about needs the user's
 * confirmation. Masked data passes (it is masked when sent).
 */
final class PersonalDataCheck
{
    public function __construct(private readonly PersonalDataScanner $scanner) {}

    /**
     * @return array{action: 'block'|'warn', kinds: list<string>}|null null: the message may be sent
     */
    public function refusal(string $text, bool $confirmed): ?array
    {
        $blocked = $this->kinds($text, 'block');

        if ($blocked !== []) {
            return ['action' => 'block', 'kinds' => $blocked];
        }

        $warned = $confirmed ? [] : $this->kinds($text, 'warn');

        return $warned !== [] ? ['action' => 'warn', 'kinds' => $warned] : null;
    }

    /**
     * @return list<string>
     */
    private function kinds(string $text, string $action): array
    {
        return array_values(array_unique(array_map(
            fn (Detection $detection): string => $detection->kind,
            $this->scanner->scan($text, [$action]),
        )));
    }
}
