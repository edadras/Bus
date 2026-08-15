<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Models\AuditLog;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Records every sensitive action. Kept deliberately simple and synchronous:
 * an audit entry that might be lost in a queue failure is not an audit entry.
 */
class AuditLogger
{
    /** Attributes that must never be written into the audit trail. */
    private const REDACTED = ['password', 'secret', 'code_hash', 'remember_token', 'iban', 'national_code'];

    public function log(
        string $action,
        ?Model $subject = null,
        ?User $actor = null,
        array $before = [],
        array $after = [],
        array $context = [],
    ): AuditLog {
        $actor ??= auth()->user();

        return AuditLog::create([
            'user_id' => $actor?->id,
            'city_id' => $context['city_id'] ?? $actor?->city_id,
            'action' => $action,
            'auditable_type' => $subject !== null ? $subject::class : null,
            'auditable_id' => $subject?->getKey(),
            'before' => $this->redact($before) ?: null,
            'after' => $this->redact($after) ?: null,
            'context' => $context ?: null,
            'ip_address' => request()->ip(),
            'user_agent' => substr((string) request()->userAgent(), 0, 500),
            'created_at' => now(),
        ]);
    }

    /** Convenience for the common "model changed" case. */
    public function logChange(string $action, Model $model, ?User $actor = null, array $context = []): AuditLog
    {
        return $this->log(
            action: $action,
            subject: $model,
            actor: $actor,
            before: array_intersect_key($model->getOriginal(), $model->getDirty()),
            after: $model->getDirty(),
            context: $context,
        );
    }

    private function redact(array $data): array
    {
        foreach (self::REDACTED as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = '[redacted]';
            }
        }

        return $data;
    }
}
