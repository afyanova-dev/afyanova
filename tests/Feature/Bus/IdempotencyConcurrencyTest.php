<?php

declare(strict_types=1);

namespace Tests\Feature\Bus;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | IC §5 / §14 / AGENTS.md §25 — idempotency is an ATOMIC CLAIM, not a
 | check-then-act. Proven against real PostgreSQL with genuinely independent
 | committed transactions on `pgsql_raw` (its own session, outside the
 | RefreshDatabase wrap, no Eloquent scope). The claim + read logic here is a
 | byte-for-byte mirror of App\Shared\Idempotency\IdempotencyStore.
 |
 | NOTE on the in_progress / 409 path: in the normal bus path
 | `ClaimIdempotencyKey` claims AND completes inside the ONE `Tenancy::run`
 | transaction, so a concurrent second dispatch of the same key BLOCKS on the
 | uncommitted row until the first commits — by which time it is `completed` and
 | the second dispatch replays the stored result. A committed `in_progress` row
 | is observable only when a claim is committed on its own (a crash between
 | claim-commit and complete, or a future async handler); that
 | `IdempotencyConflict` → 409 path is covered by
 | `CommandBusIntegrationTest > "command bus throws IdempotencyConflict for in-progress key"`.
 */

uses(RefreshDatabase::class);

final readonly class DispenseResult
{
    public function __construct(public string $dispensationId, public string $status) {}
}

/** One `INSERT … ON CONFLICT DO NOTHING` in its own committed transaction on pgsql_raw. */
function rawClaim(string $tenantId, string $scope, string $key): int
{
    $raw = DB::connection('pgsql_raw');

    return $raw->transaction(function () use ($raw, $tenantId, $scope, $key): int {
        $raw->statement("select set_config('app.tenant_id', ?, true)", [$tenantId]);

        return $raw->table('shared_idempotency_keys')->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'scope' => $scope,
            'key' => $key,
            'status' => 'in_progress',
            'result' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });
}

/** @return array{status: string, result: mixed} */
function rawResolve(string $tenantId, string $scope, string $key): array
{
    $raw = DB::connection('pgsql_raw');

    return $raw->transaction(function () use ($raw, $tenantId, $scope, $key): array {
        $raw->statement("select set_config('app.tenant_id', ?, true)", [$tenantId]);
        $row = $raw->table('shared_idempotency_keys')
            ->where('tenant_id', $tenantId)->where('scope', $scope)->where('key', $key)
            ->first();

        return [
            'status' => (string) $row->status,
            'result' => $row->result === null ? null : unserialize((string) $row->result),
        ];
    });
}

function rawComplete(string $tenantId, string $scope, string $key, mixed $result): void
{
    $raw = DB::connection('pgsql_raw');

    $raw->transaction(function () use ($raw, $tenantId, $scope, $key, $result): void {
        $raw->statement("select set_config('app.tenant_id', ?, true)", [$tenantId]);
        $raw->table('shared_idempotency_keys')
            ->where('tenant_id', $tenantId)->where('scope', $scope)->where('key', $key)
            ->update(['status' => 'completed', 'result' => serialize($result), 'updated_at' => now()]);
    });
}

// rows are committed on pgsql_raw (outside the RefreshDatabase wrap); this file
// never touches shared_idempotency_keys on the default connection, so TRUNCATE
// on pgsql_raw takes no competing lock.
afterEach(fn () => DB::connection('pgsql_raw')->statement('truncate shared_idempotency_keys'));

it('lets exactly one of two independent claimants win the key (atomic claim, real Postgres)', function () {
    $tenantId = (string) Str::uuid7();
    $scope = 'Pharmacy\\DispenseMedication';
    $key = (string) Str::uuid7();

    $first = rawClaim($tenantId, $scope, $key);   // committed
    $second = rawClaim($tenantId, $scope, $key);  // committed, same key

    expect($first)->toBe(1)     // claimant A owns it
        ->and($second)->toBe(0); // claimant B lost — the unique constraint rejected the second row

    expect(rawResolve($tenantId, $scope, $key)['status'])->toBe('in_progress');
});

it('gives the losing claimant the winner\'s completed result — same DTO type (§5 "return its stored result")', function () {
    $tenantId = (string) Str::uuid7();
    $scope = 'Pharmacy\\DispenseMedication';
    $key = (string) Str::uuid7();

    rawClaim($tenantId, $scope, $key);
    $winnerResult = new DispenseResult((string) Str::uuid7(), 'DISPENSED');
    rawComplete($tenantId, $scope, $key, $winnerResult);

    // the loser re-claims → 0 rows → resolves the committed row
    expect(rawClaim($tenantId, $scope, $key))->toBe(0);

    $resolved = rawResolve($tenantId, $scope, $key);
    expect($resolved['status'])->toBe('completed')
        ->and($resolved['result'])->toBeInstanceOf(DispenseResult::class)
        ->and($resolved['result'])->toEqual($winnerResult);
});
