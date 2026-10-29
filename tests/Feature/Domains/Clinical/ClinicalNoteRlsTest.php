<?php

declare(strict_types=1);

use App\Domains\Clinical\Domain\Model\ClinicalNote;
use App\Domains\Clinical\Domain\Model\ClinicalNoteAmendment;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | IC §17.2 — tenant isolation at BOTH layers for clin_notes and
 | clin_note_amendments (12-clinical-domain.md Invariant 1; ADR-132). Mirrors
 | tests/Feature/Domains/Encounter/EncounterRlsTest.php.
 */

uses(RefreshDatabase::class);

test('application layer: a clinical note created under tenant A is invisible under tenant B', function () {
    $tenantA = (string) Str::uuid7();
    $tenantB = (string) Str::uuid7();

    $noteId = TenantContextScope::run($tenantA, null, fn () => (string) ClinicalNote::factory()->forTenant($tenantA)->create()->id);

    expect(TenantContextScope::run($tenantA, null, fn () => ClinicalNote::query()->find($noteId)))->not->toBeNull();
    expect(TenantContextScope::run($tenantB, null, fn () => ClinicalNote::query()->find($noteId)))->toBeNull();
});

it('FORCEs RLS on clin_notes (database layer)', function () {
    $raw = DB::connection('pgsql_raw');
    $tenantA = (string) Str::uuid7();
    $tenantB = (string) Str::uuid7();
    $rowId = (string) Str::uuid7();

    $row = [
        'facility_id' => (string) Str::uuid7(),
        'patient_id' => (string) Str::uuid7(),
        'encounter_id' => (string) Str::uuid7(),
        'author_user_id' => (string) Str::uuid7(),
        'note_type' => 'progress_note',
        'body' => 'raw insert',
        'occurred_at' => now(),
        'recorded_at' => now(),
        'version' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    $raw->transaction(function () use ($raw, $row, $tenantA, $rowId): void {
        $raw->statement("select set_config('app.tenant_id', ?, true)", [$tenantA]);
        $raw->table('clin_notes')->insert([...$row, 'id' => $rowId, 'tenant_id' => $tenantA]);
    });

    assertRlsIsolates('clin_notes', $tenantA, $rowId, $tenantB, $row);
});

it('FORCEs RLS on clin_note_amendments (database layer) and is append-only', function () {
    $raw = DB::connection('pgsql_raw');
    $tenantA = (string) Str::uuid7();
    $tenantB = (string) Str::uuid7();
    $rowId = (string) Str::uuid7();

    // a parent note (intra-domain FK target)
    $noteId = (string) Str::uuid7();
    $noteRow = [
        'id' => $noteId, 'tenant_id' => $tenantA, 'facility_id' => (string) Str::uuid7(),
        'patient_id' => (string) Str::uuid7(), 'encounter_id' => (string) Str::uuid7(),
        'author_user_id' => (string) Str::uuid7(), 'note_type' => 'progress_note', 'body' => 'x',
        'status' => 'SIGNED', 'occurred_at' => now(), 'recorded_at' => now(), 'version' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ];

    $row = [
        'facility_id' => (string) Str::uuid7(),
        'clinical_note_id' => $noteId,
        'patient_id' => (string) Str::uuid7(),
        'encounter_id' => (string) Str::uuid7(),
        'amendment_type' => 'ADDENDUM',
        'body' => 'raw amendment',
        'reason' => 'raw',
        'author_user_id' => (string) Str::uuid7(),
        'occurred_at' => now(),
        'recorded_at' => now(),
        'version' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    $raw->transaction(function () use ($raw, $noteRow, $row, $tenantA, $rowId): void {
        $raw->statement("select set_config('app.tenant_id', ?, true)", [$tenantA]);
        $raw->table('clin_notes')->insert($noteRow);
        $raw->table('clin_note_amendments')->insert([...$row, 'id' => $rowId, 'tenant_id' => $tenantA]);
    });

    // append-only: even the owning tenant cannot UPDATE / DELETE
    $raw->transaction(function () use ($raw, $tenantA, $rowId): void {
        $raw->statement("select set_config('app.tenant_id', ?, true)", [$tenantA]);
        expect($raw->table('clin_note_amendments')->where('id', $rowId)->update(['reason' => 'tampered']))->toBe(0);
        expect($raw->table('clin_note_amendments')->where('id', $rowId)->delete())->toBe(0);
    });

    // the cross-tenant read isolation still holds (the WITH CHECK insert probe uses the note FK,
    // so exercise only the read side here)
    $raw->transaction(function () use ($raw, $tenantB, $rowId): void {
        $raw->statement("select set_config('app.tenant_id', ?, true)", [$tenantB]);
        expect($raw->table('clin_note_amendments')->where('id', $rowId)->count())->toBe(0);
    });

    // FORCE + FOR CHECK: relforcerowsecurity is on
    $forced = $raw->table('pg_class')->where('relname', 'clin_note_amendments')->value('relforcerowsecurity');
    expect((bool) $forced)->toBeTrue();
});

test('a Clinical model never leaks across tenants via the ORM even without an explicit where', function () {
    $tenantA = (string) Str::uuid7();
    $tenantB = (string) Str::uuid7();

    TenantContextScope::run($tenantA, null, function () use ($tenantA): void {
        $noteId = (string) ClinicalNote::factory()->forTenant($tenantA)->signed()->create()->id;
        ClinicalNoteAmendment::factory()->forTenant($tenantA)->forNote($noteId)->create();
    });

    expect(TenantContextScope::run($tenantB, null, fn () => ClinicalNoteAmendment::query()->count()))->toBe(0);
    expect(TenantContextScope::run($tenantA, null, fn () => ClinicalNoteAmendment::query()->count()))->toBe(1);
});
