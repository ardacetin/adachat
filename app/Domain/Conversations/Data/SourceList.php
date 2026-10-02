<?php

namespace App\Domain\Conversations\Data;

use App\Domain\AI\Data\Events\SourceFound;
use Illuminate\Support\Str;

/**
 * The web pages behind an answer: the sources the answer cites, or, when it
 * cites none, the first search results. Only http(s) links are kept, each
 * once.
 */
final class SourceList
{
    public const MAX_CITED = 20;

    public const MAX_RESULTS = 5;

    /** @var array<string, array{url: string, title: string|null}> */
    private array $cited = [];

    /** @var array<string, array{url: string, title: string|null}> */
    private array $results = [];

    /**
     * @return array{url: string, title: string|null}|null the source when it is a new cited one
     */
    public function add(SourceFound $found): ?array
    {
        $url = trim($found->url);

        if (strlen($url) > 2048 || ! preg_match('#^https?://[^\s/]+#i', $url)) {
            return null;
        }

        $source = [
            'url' => $url,
            'title' => $found->title === null ? null : Str::limit(Str::squish($found->title), 200),
        ];

        if (! $found->cited) {
            if (! isset($this->results[$url]) && count($this->results) < self::MAX_RESULTS) {
                $this->results[$url] = $source;
            }

            return null;
        }

        if (isset($this->cited[$url]) || count($this->cited) >= self::MAX_CITED) {
            return null;
        }

        return $this->cited[$url] = $source;
    }

    /**
     * @return list<array{url: string, title: string|null}>
     */
    public function toArray(): array
    {
        return array_values($this->cited !== [] ? $this->cited : $this->results);
    }
}
