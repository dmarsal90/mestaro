<?php

declare(strict_types=1);

namespace App\Infrastructure\Audit;

use App\Application\Audit\AuditTrail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DatabaseAuditTrail implements AuditTrail
{
    private const SENSITIVE_KEY = '/pass|token|secret|authorization|cookie|key/i';

    public function record(string $action, ?string $actor = null, ?string $subjectType = null, ?string $subjectId = null, array $metadata = []): void
    {
        $request = app()->runningInConsole() ? null : request();
        $metadata = $this->scrub($metadata);

        DB::table('audit_logs')->insert([
            'actor_user_id' => $actor,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
            'ip' => $request?->ip(),
            'user_agent' => $request === null ? null : Str::limit((string) $request->userAgent(), 255, ''),
        ]);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function scrub(array $data): array
    {
        $clean = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE_KEY, $key) === 1) {
                continue;
            }
            $clean[$key] = is_array($value) ? $this->scrub($value) : $value;
        }

        return $clean;
    }
}
