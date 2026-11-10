<?php

declare(strict_types=1);

namespace App\Application\Audit;

interface AuditTrail
{
    /**
     * Appends one entry. Secrets (passwords, tokens) must never be passed; implementations drop them defensively.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function record(string $action, ?string $actor = null, ?string $subjectType = null, ?string $subjectId = null, array $metadata = []): void;
}
