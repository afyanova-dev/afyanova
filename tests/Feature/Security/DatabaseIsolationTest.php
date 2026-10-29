<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | PHASE 2 gate — DB-layer tenant isolation, proven on `pgsql_raw` (a second
 | connection with NO Eloquent global scopes and NO Tenancy::run), independent
 | of any application filtering (blueprint.md §7; docs 05 §87–§88).
 |
 | Rows are seeded straight onto pgsql_raw (they are not part of the
 | RefreshDatabase transaction) and the stub tables are truncated afterwards.
 */

afterEach(function (): void {
    DB::connection('pgsql_raw')->statement('truncate org_facilities, shared_audit_log');
});

/** @return array<string, string> the non-null columns a bare org_facilities row needs */
function facilityRowTemplate(): array
{
    return [
        'organization_id' => (string) Str::uuid7(),
        'name' => 'F', 'code' => 'F'.substr((string) Str::uuid7(), 0, 8),
        'facility_type' => 'DISPENSARY', 'care_level' => 'PRIMARY', 'ownership_type' => 'PUBLIC',
        'status' => 'ACTIVE',
    ];
}

function seedFacility(string $tenantId): string
{
    $raw = DB::connection('pgsql_raw');
    $id = (string) Str::uuid7();

    $raw->transaction(function () use ($raw, $tenantId, $id): void {
        $raw->statement("select set_config('app.tenant_id', ?, true)", [$tenantId]);
        $raw->table('org_facilities')->insert([
            ...facilityRowTemplate(),
            'id' => $id, 'tenant_id' => $tenantId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    });

    return $id;
}

it('isolates a tenant-scoped table at the database — read, no-context, empty-string, and cross-tenant write', function (): void {
    $tenantA = (string) Str::uuid7();
    $tenantB = (string) Str::uuid7();
    $rowA = seedFacility($tenantA);

    // own tenant sees it
    DB::connection('pgsql_raw')->transaction(function () use ($tenantA, $rowA): void {
        DB::connection('pgsql_raw')->statement("select set_config('app.tenant_id', ?, true)", [$tenantA]);
        expect(DB::connection('pgsql_raw')->table('org_facilities')->where('id', $rowA)->count())->toBe(1);
    });

    // wrong tenant → 0; no context → 0 (no error); '' context → 0 (no error);
    // cross-tenant write → WITH CHECK rejects
    assertRlsIsolates('org_facilities', $tenantA, $rowA, $tenantB, facilityRowTemplate());
});

it('keeps a tenant from inserting an untenanted audit row, but lets a system op write one', function (): void {
    $tenantA = (string) Str::uuid7();
    $raw = DB::connection('pgsql_raw');

    // system op: no context → NULL-tenant row is allowed
    $raw->transaction(function () use ($raw): void {
        $raw->statement("select set_config('app.tenant_id', '', true)");
        $raw->table('shared_audit_log')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => null, 'action' => 'system.rls_bypass',
            'metadata' => '{}', 'created_at' => now(),
        ]);
    });
    expect(true)->toBeTrue(); // reached here → the insert passed

    // a tenant may NOT smuggle in a NULL-tenant row
    expect(fn () => $raw->transaction(function () use ($raw, $tenantA): void {
        $raw->statement("select set_config('app.tenant_id', ?, true)", [$tenantA]);
        $raw->table('shared_audit_log')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => null, 'action' => 'x', 'metadata' => '{}', 'created_at' => now(),
        ]);
    }))->toThrow(QueryException::class);

    // and a tenant cannot read the system row
    $raw->transaction(function () use ($raw, $tenantA): void {
        $raw->statement("select set_config('app.tenant_id', ?, true)", [$tenantA]);
        expect($raw->table('shared_audit_log')->count())->toBe(0);
    });
});

it('append-only: a tenant cannot UPDATE or DELETE audit rows', function (): void {
    $tenantA = (string) Str::uuid7();
    $raw = DB::connection('pgsql_raw');

    $raw->transaction(function () use ($raw, $tenantA): void {
        $raw->statement("select set_config('app.tenant_id', ?, true)", [$tenantA]);
        $raw->table('shared_audit_log')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantA, 'action' => 'test.event',
            'metadata' => '{}', 'created_at' => now(),
        ]);
    });

    $raw->transaction(function () use ($raw, $tenantA): void {
        $raw->statement("select set_config('app.tenant_id', ?, true)", [$tenantA]);
        expect($raw->table('shared_audit_log')->where('action', 'test.event')->update(['action' => 'tampered']))->toBe(0);
        expect($raw->table('shared_audit_log')->where('action', 'test.event')->delete())->toBe(0);
        expect($raw->table('shared_audit_log')->where('action', 'test.event')->count())->toBe(1);
    });
});

it('is PgBouncer-transaction-mode safe: the tenant GUC does not survive its transaction', function (): void {
    $raw = DB::connection('pgsql_raw');

    // set the GUC transaction-locally, then commit
    $raw->transaction(function () use ($raw): void {
        $raw->statement("select set_config('app.tenant_id', ?, true)", ['11111111-1111-1111-1111-111111111111']);
        expect($raw->selectOne("select current_setting('app.tenant_id', true) as v")->v)
            ->toBe('11111111-1111-1111-1111-111111111111');
    });

    // a fresh transaction on the SAME (pooled) connection sees no residual context
    $raw->transaction(function () use ($raw): void {
        expect((string) ($raw->selectOne("select current_setting('app.tenant_id', true) as v")->v ?? ''))->toBe('');
    });
})->group('security');
