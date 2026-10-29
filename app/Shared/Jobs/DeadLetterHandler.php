<?php

declare(strict_types=1);

namespace App\Shared\Jobs;

use App\Shared\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Persists unrecoverable job / relay failures to `shared_dead_letters` (IMPLEMENTATION-CONTRACTS.md §14).
 * Table is RLS-enabled and append-only.
 */
final class DeadLetterHandler
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function record(
        string $tenantId,
        string $sourceType,
        string $sourceId,
        mixed $payload,
        Throwable $error,
        ?string $correlationId = null,
        ?string $causationId = null,
    ): void {
        DB::table('shared_dead_letters')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'error' => $error->getMessage()."\n".$error->getTraceAsString(),
            'correlation_id' => $correlationId,
            'causation_id' => $causationId,
            'created_at' => now(),
        ]);

        $this->auditLogger->recordFor(
            tenantId: $tenantId,
            action: 'dead_letter.created',
            reason: sprintf('Unrecoverable failure in [%s]: %s', $sourceType, $error->getMessage()),
            metadata: [
                'source_type' => $sourceType,
                'source_id' => $sourceId,
            ],
        );
    }
}
