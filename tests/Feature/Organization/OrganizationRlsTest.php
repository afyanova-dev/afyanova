<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | PHASE 3 gate — every tenant-scoped `org_` table is isolated at the DATABASE
 | (FORCE'd RLS), proven on `pgsql_raw` with no Eloquent scope in play
 | (blueprint.md §8; IC §10, §11.2; docs 05 §87–§88).
 |
 | Reference tables (`org_facility_types`, `org_care_levels`,
 | `org_ownership_types`, `org_capabilities`, `org_capability_dependencies`) are
 | global — no `tenant_id`, no RLS — and are intentionally not listed here.
 */

/** @return array<string, array{0: string, 1: array<string, mixed>}> label => [table, full non-null row minus id/tenant_id] */
function orgTenantScopedRows(): array
{
    $fid = fn (): string => (string) Str::uuid7();
    $code = fn (string $p): string => $p.'_'.substr((string) Str::uuid7(), 0, 8);

    return [
        'org_organizations' => ['org_organizations', [
            'name' => 'Org', 'code' => $code('ORG'), 'status' => 'ACTIVE',
        ]],
        'org_facilities' => ['org_facilities', [
            'organization_id' => $fid(), 'name' => 'Fac', 'code' => $code('FAC'),
            'facility_type' => 'DISPENSARY', 'care_level' => 'PRIMARY', 'ownership_type' => 'PUBLIC', 'status' => 'ACTIVE',
        ]],
        'org_departments' => ['org_departments', [
            'facility_id' => $fid(), 'name' => 'Dept', 'code' => $code('DEPT'),
        ]],
        'org_units' => ['org_units', [
            'facility_id' => $fid(), 'department_id' => $fid(), 'name' => 'Unit', 'code' => $code('UNIT'),
        ]],
        'org_rooms' => ['org_rooms', [
            'facility_id' => $fid(), 'name' => 'Room', 'code' => $code('ROOM'),
        ]],
        'org_facility_capabilities' => ['org_facility_capabilities', [
            'facility_id' => $fid(), 'capability_code' => 'PHARMACY', 'state' => 'ENABLED',
        ]],
        'org_facility_services' => ['org_facility_services', [
            'facility_id' => $fid(), 'capability_code' => 'PHARMACY', 'service_code' => $code('SVC'), 'name' => 'Svc',
        ]],
        'org_organization_policies' => ['org_organization_policies', [
            'organization_id' => $fid(), 'key' => 'k', 'value' => '{}',
        ]],
        'org_facility_policies' => ['org_facility_policies', [
            'facility_id' => $fid(), 'key' => 'k', 'value' => '{}',
        ]],
        'org_facility_operating_hours' => ['org_facility_operating_hours', [
            'facility_id' => $fid(), 'day_of_week' => 1, 'closed' => true,
        ]],
    ];
}

it('FORCEs RLS on every tenant-scoped org_ table', function (string $table, array $row): void {
    $raw = DB::connection('pgsql_raw');
    $tenantA = (string) Str::uuid7();
    $tenantB = (string) Str::uuid7();
    $rowId = (string) Str::uuid7();

    $raw->transaction(function () use ($raw, $table, $row, $tenantA, $rowId): void {
        $raw->statement("select set_config('app.tenant_id', ?, true)", [$tenantA]);
        $raw->table($table)->insert([...$row, 'id' => $rowId, 'tenant_id' => $tenantA]);
    });

    assertRlsIsolates($table, $tenantA, $rowId, $tenantB, $row);

    $raw->statement("truncate {$table}");
})->with(orgTenantScopedRows());
