<?php

namespace App\Domain\Institution\Services;

use App\Domain\Institution\Settings\ContentSettings;
use Illuminate\Support\Facades\Lang;

/**
 * The texts administrators may reword, resolved per locale: the saved
 * text where there is one, Ada's default from the lang files otherwise,
 * with the :placeholders filled in.
 */
final class ContentTexts
{
    public const LANDING = ['title', 'lead', 'sign_in_hint', 'models_title', 'models_text', 'budget_title', 'budget_text', 'privacy_title', 'privacy_text', 'footer'];

    public const INVITATION = ['subject', 'heading', 'body', 'sign_in', 'button'];

    private const PREFIX = ['landing' => 'landing', 'invitation' => 'mail.invitation'];

    public function __construct(private readonly ContentSettings $settings) {}

    /**
     * @param  array<string, string>  $replace  placeholder (without the colon) → value
     * @return array<string, string>
     */
    public function landing(string $locale, array $replace): array
    {
        return $this->resolve('landing', self::LANDING, $this->settings->landing, $locale, $replace);
    }

    /**
     * @param  array<string, string>  $replace
     * @return array<string, string>
     */
    public function invitation(string $locale, array $replace): array
    {
        return $this->resolve('invitation', self::INVITATION, $this->settings->invitation_email, $locale, $replace);
    }

    /**
     * Ada's defaults with their placeholders, for the admin page.
     *
     * @param  'landing'|'invitation'  $kind
     * @return array<string, array<string, string>> locale → field → text
     */
    public function defaults(string $kind): array
    {
        $fields = $kind === 'landing' ? self::LANDING : self::INVITATION;
        $out = [];

        foreach ((array) config('ada.locales.available') as $locale) {
            foreach ($fields as $field) {
                $out[$locale][$field] = (string) Lang::get(self::PREFIX[$kind].'.'.$field, [], $locale);
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $fields
     * @param  array<string, array<string, string>>  $overrides
     * @param  array<string, string>  $replace
     * @return array<string, string>
     */
    private function resolve(string $kind, array $fields, array $overrides, string $locale, array $replace): array
    {
        $placeholders = [];

        foreach ($replace as $placeholder => $value) {
            $placeholders[':'.$placeholder] = $value;
        }

        $out = [];

        foreach ($fields as $field) {
            $custom = $overrides[$locale][$field] ?? null;
            $text = is_string($custom) && trim($custom) !== ''
                ? $custom
                : (string) Lang::get(self::PREFIX[$kind].'.'.$field, [], $locale);

            $out[$field] = strtr($text, $placeholders);
        }

        return $out;
    }
}
