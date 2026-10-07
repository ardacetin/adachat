<?php

namespace App\Domain\PersonalData;

/**
 * The built-in detectors. Numbers with a check digit (Turkish identity
 * numbers, IBANs, card numbers) are only reported when the check digit is
 * right, so ordinary numbers are rarely mistaken for them.
 */
final class Detectors
{
    public const KINDS = ['tckn', 'iban', 'card', 'phone', 'email'];

    /**
     * @return list<Detection>
     */
    public static function find(string $kind, string $text): array
    {
        return match ($kind) {
            'tckn' => self::matches($kind, '/(?<!\d)[1-9]\d{10}(?!\d)/', $text, self::validTckn(...)),
            'iban' => self::ibans($text),
            'card' => self::matches($kind, '/(?<![\d-])[2-6](?:[ -]?\d){12,18}(?![\d-])/', $text, fn (string $value): bool => self::luhn(preg_replace('/\D/', '', $value) ?? '')),
            // Turkish numbers: mobiles with or without a prefix, landlines with one.
            'phone' => self::matches($kind, '/(?<![\d+(])(?:\(?(?:\+90|0090|0)[ -]?\(?[2-5]\d{2}\)?|\(?5\d{2}\)?)[ -]?\d{3}[ -]?\d{2}[ -]?\d{2}(?!\d)/', $text),
            'email' => self::matches($kind, '/[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}/', $text),
            default => [],
        };
    }

    /**
     * An 11-digit number whose last two digits are its check digits.
     */
    public static function validTckn(string $value): bool
    {
        if (preg_match('/^[1-9]\d{10}$/', $value) !== 1) {
            return false;
        }

        $d = array_map(intval(...), str_split($value));
        $odd = $d[0] + $d[2] + $d[4] + $d[6] + $d[8];
        $even = $d[1] + $d[3] + $d[5] + $d[7];

        return ((($odd * 7) - $even) % 10 + 10) % 10 === $d[9]
            && array_sum(array_slice($d, 0, 10)) % 10 === $d[10];
    }

    public static function validIban(string $value): bool
    {
        $iban = strtoupper(str_replace(' ', '', $value));

        if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban) !== 1) {
            return false;
        }

        $digits = '';

        foreach (str_split(substr($iban, 4).substr($iban, 0, 4)) as $char) {
            $digits .= ctype_digit($char) ? $char : (string) (ord($char) - 55);
        }

        $remainder = 0;

        foreach (str_split($digits, 7) as $chunk) {
            $remainder = (int) ($remainder.$chunk) % 97;
        }

        return $remainder === 1;
    }

    public static function luhn(string $digits): bool
    {
        if (preg_match('/^\d{13,19}$/', $digits) !== 1) {
            return false;
        }

        $sum = 0;

        foreach (array_reverse(str_split($digits)) as $i => $digit) {
            $digit = (int) $digit * ($i % 2 === 1 ? 2 : 1);
            $sum += $digit > 9 ? $digit - 9 : $digit;
        }

        return $sum % 10 === 0;
    }

    /**
     * IBANs may be written in groups of four; a candidate may also run into
     * the next word, so the longest valid prefix wins.
     *
     * @return list<Detection>
     */
    private static function ibans(string $text): array
    {
        preg_match_all('/\b[A-Z]{2}\d{2}(?: ?[A-Z0-9]){11,40}/', $text, $found, PREG_OFFSET_CAPTURE);
        $detections = [];

        foreach ($found[0] as [$candidate, $offset]) {
            for ($length = strlen($candidate); $length >= 15; $length--) {
                $value = rtrim(substr($candidate, 0, $length));

                if (strlen($value) === $length && self::validIban($value) && ! ctype_alnum(substr($candidate, $length, 1))) {
                    $detections[] = new Detection('iban', $offset, $value);
                    break;
                }
            }
        }

        return $detections;
    }

    /**
     * @param  (callable(string): bool)|null  $valid
     * @return list<Detection>
     */
    private static function matches(string $kind, string $pattern, string $text, ?callable $valid = null): array
    {
        preg_match_all($pattern, $text, $found, PREG_OFFSET_CAPTURE);
        $detections = [];

        foreach ($found[0] as [$value, $offset]) {
            if ($valid === null || $valid($value)) {
                $detections[] = new Detection($kind, $offset, $value);
            }
        }

        return $detections;
    }
}
