<?php

namespace App\Domain\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Records administrative actions. Callers pass the values that changed;
 * anything that looks like a secret is redacted before it is stored.
 */
final class AuditLogger
{
    public const REDACTED = '[redacted]';

    /** Keys whose values are never stored. */
    private const SECRET_KEY_PATTERN = '/secret|password|api[_-]?key|credential|(^|_)(access|refresh|remember|auth|bearer|session)?_?token$/i';

    public function __construct(private readonly Request $request) {}

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    public function record(string $action, Model|string|null $subject = null, array $old = [], array $new = []): AuditLog
    {
        $actor = Auth::user();
        $actor = $actor instanceof User ? $actor : null;

        $log = new AuditLog;
        $log->forceFill([
            'actor_id' => $actor?->id,
            'actor_type' => $actor !== null ? 'user' : (app()->runningInConsole() ? 'cli' : 'system'),
            'action' => $action,
            'subject_type' => match (true) {
                $subject instanceof Model => class_basename($subject),
                default => $subject,
            },
            'subject_id' => $subject instanceof Model ? (string) $subject->getKey() : null,
            'old_values' => $old === [] ? null : self::redact($old),
            'new_values' => $new === [] ? null : self::redact($new),
            'ip_address' => $actor !== null ? $this->request->ip() : null,
            'user_agent' => $actor !== null ? Str::limit((string) $this->request->userAgent(), 509) : null,
        ])->save();

        return $log;
    }

    /**
     * Keep only the keys whose values differ.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function diff(array $before, array $after): array
    {
        $old = [];
        $new = [];

        foreach ($after as $key => $value) {
            if (! array_key_exists($key, $before) || $before[$key] !== $value) {
                $old[$key] = $before[$key] ?? null;
                $new[$key] = $value;
            }
        }

        return [$old, $new];
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    public static function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET_KEY_PATTERN, $key) === 1) {
                $values[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                $values[$key] = self::redact($value);
            }
        }

        return $values;
    }
}
