<?php

namespace App\Domain\PersonalData;

/**
 * Names of the kinds for people: the built-in kinds are translated, an
 * institution pattern goes by the name it was given.
 */
final class PersonalDataLabels
{
    public static function label(string $kind): string
    {
        return in_array($kind, Detectors::KINDS, true) ? __('chat.personal_data.kinds.'.$kind) : $kind;
    }
}
