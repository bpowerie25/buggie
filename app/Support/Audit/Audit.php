<?php

namespace App\Support\Audit;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The one place that writes the audit trail.
 *
 * Model changes arrive through the {@see \App\Models\Concerns\Audited} trait; things
 * that are not model changes (sign-ins, membership) call {@see self::log()} directly.
 *
 * Never throws into the request it is describing: a trail that can break the action
 * it records would be switched off by the first person it got in the way of.
 */
final class Audit
{
    /** Attributes that are noise, however they change. */
    private const NOISE = [
        'updated_at', 'created_at', 'deleted_at', 'last_seen_at', 'search_vector',
        'description_text', 'rank', 'issue_sequence', 'last_used_at', 'remember_token',
    ];

    /** Attributes that are secrets, whichever model they are on. */
    private const SECRET = ['password', 'secret', 'token', 'two_factor_secret', 'two_factor_recovery_codes', 'url'];

    /**
     * @param  array<string, mixed>|null  $changes
     */
    public static function log(
        string $event,
        ?Model $subject = null,
        ?array $changes = null,
        ?int $workspaceId = null,
        ?User $actor = null,
        ?string $label = null,
    ): void {
        try {
            $workspaceId ??= app(Tenancy::class)->id();

            if ($workspaceId === null) {
                return;
            }

            $actor ??= auth()->user();

            AuditLog::forceCreate([
                'workspace_id' => $workspaceId,
                'actor_id' => $actor?->id,
                'actor_name' => $actor?->name,
                'event' => $event,
                'subject_type' => $subject ? Str::snake(class_basename($subject)) : null,
                'subject_id' => $subject?->getKey(),
                'subject_label' => Str::limit($label ?? self::labelOf($subject), 190, ''),
                'changes' => $changes === [] ? null : $changes,
                'ip' => app()->bound('request') && ! app()->runningInConsole() ? request()->ip() : null,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Before and after for what changed on a model, minus noise and secrets. */
    public static function diff(Model $model): array
    {
        $skip = [...self::NOISE, ...self::SECRET, ...$model->getHidden(), ...(property_exists($model, 'auditExclude') ? $model->auditExclude : [])];
        $out = [];

        foreach ($model->getChanges() as $key => $new) {
            if (in_array($key, $skip, true)) {
                continue;
            }

            $out[$key] = ['from' => self::scalar($model->getOriginal($key)), 'to' => self::scalar($new)];
        }

        return $out;
    }

    /** Just the values a new row started with, same exclusions. */
    public static function snapshot(Model $model): array
    {
        $skip = [...self::NOISE, ...self::SECRET, ...$model->getHidden(), ...(property_exists($model, 'auditExclude') ? $model->auditExclude : []), 'id', 'workspace_id'];
        $out = [];

        foreach ($model->getAttributes() as $key => $value) {
            if (! in_array($key, $skip, true) && $value !== null) {
                $out[$key] = self::scalar($value);
            }
        }

        return $out;
    }

    private static function scalar(mixed $value): mixed
    {
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value);
        }

        return is_string($value) ? Str::limit($value, 200) : $value;
    }

    private static function labelOf(?Model $model): ?string
    {
        if ($model === null) {
            return null;
        }

        foreach (['key', 'title', 'name', 'email', 'filename'] as $attribute) {
            // Read from the raw attributes: asking a model for one it was not given is
            // an exception in strict mode, and "does this model have a key?" is the question.
            if (($value = $model->getAttributes()[$attribute] ?? null) !== null) {
                return (string) $value;
            }
        }

        return '#'.$model->getKey();
    }
}
