<?php

declare(strict_types=1);

namespace App\Shared\Idempotency;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Backed by `shared_idempotency_keys` (IMPLEMENTATION-CONTRACTS.md §5, §14;
 * AGENTS.md §25). Idempotency is a **claim**, not a check:
 *
 *   INSERT ... (tenant_id, scope, key, status='in_progress') ON CONFLICT DO NOTHING
 *     1 row  → this caller owns the operation           → {@see ClaimResult::claimed()}
 *     0 rows → someone else claimed it; read the row:
 *       status = 'completed'    → return the stored result (SAME type — serialized)
 *       status = 'in_progress'  → {@see ClaimResult::inProgress()} → 409 in_progress
 */
final class IdempotencyStore
{
    public function claim(string $tenantId, string $scope, string $key): ClaimResult
    {
        $now = now();

        $inserted = DB::table('shared_idempotency_keys')->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'scope' => $scope,
            'key' => $key,
            'status' => ClaimStatus::InProgress->value,
            'result' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted > 0) {
            return ClaimResult::claimed();
        }

        $row = DB::table('shared_idempotency_keys')
            ->where('tenant_id', $tenantId)->where('scope', $scope)->where('key', $key)
            ->first();

        if ($row === null || $row->status === ClaimStatus::InProgress->value) {
            return ClaimResult::inProgress();
        }

        return ClaimResult::completed(
            $row->result === null ? null : unserialize((string) $row->result),
        );
    }

    public function complete(string $tenantId, string $scope, string $key, mixed $result = null): void
    {
        DB::table('shared_idempotency_keys')
            ->where('tenant_id', $tenantId)->where('scope', $scope)->where('key', $key)
            ->update([
                'status' => ClaimStatus::Completed->value,
                'result' => $result === null ? null : serialize($result),
                'updated_at' => now(),
            ]);
    }
}
