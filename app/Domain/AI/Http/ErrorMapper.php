<?php

namespace App\Domain\AI\Http;

use App\Domain\AI\Exceptions\ContentFiltered;
use App\Domain\AI\Exceptions\ContextLengthExceeded;
use App\Domain\AI\Exceptions\InvalidProviderRequest;
use App\Domain\AI\Exceptions\ProviderAuthFailed;
use App\Domain\AI\Exceptions\ProviderException;
use App\Domain\AI\Exceptions\ProviderOverloaded;
use App\Domain\AI\Exceptions\ProviderRateLimited;
use App\Domain\AI\Exceptions\ProviderTimeout;
use App\Domain\AI\Exceptions\ProviderUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;

/**
 * Maps provider HTTP failures to Ada exceptions. Only the provider's error
 * type/code and a short, key-free message are kept.
 */
final class ErrorMapper
{
    private const CONTEXT_PATTERN = '/context[_ ]length|context window|prompt is too long|too many tokens|maximum number of tokens|input token count/i';

    public static function fromResponse(string $provider, Response $response): ProviderException
    {
        $status = $response->status();
        [$type, $message] = self::details(self::body($response));
        $summary = self::summary($provider, $status, $type, $message);

        return match (true) {
            $status === 401, $status === 403 => new ProviderAuthFailed($summary, $status),
            $status === 429 => new ProviderRateLimited($summary, $status),
            $status === 529, $status === 503 => new ProviderOverloaded($summary, $status),
            ($status === 400 || $status === 413) && preg_match(self::CONTEXT_PATTERN, $type.' '.$message) === 1 => new ContextLengthExceeded($summary, $status),
            // A redirect (not followed) means the base URL is wrong.
            $status >= 300 && $status < 500 => new InvalidProviderRequest($summary, $status),
            default => new ProviderUnavailable($summary, $status),
        };
    }

    /**
     * At most the first 64 KiB of the error body: a streamed error response
     * is otherwise read whole, whatever its size.
     */
    private static function body(Response $response): mixed
    {
        $stream = $response->toPsrResponse()->getBody();

        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        return json_decode($stream->read(64 * 1024), true);
    }

    /**
     * Errors reported inside an otherwise successful stream.
     */
    public static function fromStreamError(string $provider, ?string $type, ?string $message): ProviderException
    {
        $summary = self::summary($provider, null, $type ?? '', $message ?? '');
        $haystack = strtolower(($type ?? '').' '.($message ?? ''));

        return match (true) {
            str_contains($haystack, 'rate_limit') || str_contains($haystack, 'resource_exhausted') => new ProviderRateLimited($summary),
            str_contains($haystack, 'overloaded') || str_contains($haystack, 'unavailable') => new ProviderOverloaded($summary),
            preg_match(self::CONTEXT_PATTERN, $haystack) === 1 => new ContextLengthExceeded($summary),
            str_contains($haystack, 'safety') || str_contains($haystack, 'content_filter') => new ContentFiltered($summary),
            default => new ProviderUnavailable($summary),
        };
    }

    public static function fromConnection(string $provider, ConnectionException $exception): ProviderException
    {
        $timedOut = str_contains(strtolower($exception->getMessage()), 'timed out');
        $summary = "{$provider}: ".($timedOut ? 'request timed out' : 'connection failed');

        return $timedOut
            ? new ProviderTimeout($summary, previous: $exception)
            : new ProviderUnavailable($summary, previous: $exception);
    }

    /**
     * @return array{0: string, 1: string} provider error type/code and message
     */
    private static function details(mixed $body): array
    {
        if (! is_array($body)) {
            return ['', ''];
        }

        $error = is_array($body['error'] ?? null) ? $body['error'] : $body;

        $type = $error['code'] ?? $error['type'] ?? $error['status'] ?? '';
        $message = $error['message'] ?? '';

        return [is_scalar($type) ? (string) $type : '', is_string($message) ? $message : ''];
    }

    private static function summary(string $provider, ?int $status, string $type, string $message): string
    {
        // Messages from providers do not echo credentials; truncate anyway.
        $parts = array_filter([$status !== null ? "HTTP {$status}" : null, $type, mb_substr($message, 0, 200)]);

        return "{$provider}: ".implode(' — ', $parts);
    }
}
