<?php

namespace App\Domain\Institution\Services;

use App\Domain\Institution\Settings\InstitutionSettings;
use App\Domain\Institution\Theme\ThemeTokens;
use Illuminate\Support\Facades\Storage;

/**
 * Read model of the institution branding for views and Inertia props.
 */
final class Branding
{
    public function __construct(private readonly InstitutionSettings $settings) {}

    /**
     * @return array{name: string, shortName: string|null, logoUrl: string|null, logoDarkUrl: string|null, faviconUrl: string|null, supportEmail: string|null, privacyUrl: string|null, termsUrl: string|null}
     */
    public function sharedProps(): array
    {
        return [
            'name' => $this->settings->name,
            'shortName' => $this->settings->short_name,
            'logoUrl' => $this->url($this->settings->logo_path),
            'logoDarkUrl' => $this->url($this->settings->logo_dark_path ?? $this->settings->logo_path),
            'faviconUrl' => $this->url($this->settings->favicon_path),
            'supportEmail' => $this->settings->support_email,
            'privacyUrl' => $this->settings->privacy_url,
            'termsUrl' => $this->settings->terms_url,
        ];
    }

    public function themeCss(): ?string
    {
        return ThemeTokens::css($this->settings->primary_color);
    }

    public function faviconUrl(): string
    {
        return $this->url($this->settings->favicon_path) ?? '/favicon.svg';
    }

    /**
     * Root-relative URL, so assets load regardless of APP_URL or proxies.
     */
    private function url(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        $url = Storage::disk('public')->url($path);

        return parse_url($url, PHP_URL_PATH) ?: $url;
    }
}
