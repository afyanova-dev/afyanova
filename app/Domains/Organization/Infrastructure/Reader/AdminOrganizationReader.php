<?php

declare(strict_types=1);

namespace App\Domains\Organization\Infrastructure\Reader;

use Illuminate\Support\Facades\DB;

final class AdminOrganizationReader
{
    /** @return list<array{id: string, organizationId: string}> */
    public function candidateFacilities(string $tenantId): array
    {
        return array_values(DB::table('org_facilities')
            ->where('tenant_id', $tenantId)
            ->get(['id', 'organization_id'])
            ->map(static fn (object $row): array => [
                'id' => (string) $row->id,
                'organizationId' => (string) $row->organization_id,
            ])->all());
    }

    /** @return list<array{id: string, name: string}> */
    public function activeOrganizations(string $tenantId): array
    {
        return array_values(DB::table('org_organizations')
            ->where('tenant_id', $tenantId)
            ->where('status', 'ACTIVE')
            ->orderBy('id')
            ->get(['id', 'name'])
            ->map(static fn (object $row): array => [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
            ])->all());
    }

    /**
     * @param  list<string>  $organizationIds
     * @return list<array{id: string, name: string}>
     */
    public function activeOrganizationsByIds(string $tenantId, array $organizationIds): array
    {
        if ($organizationIds === []) {
            return [];
        }

        return array_values(DB::table('org_organizations')
            ->where('tenant_id', $tenantId)
            ->where('status', 'ACTIVE')
            ->whereIn('id', $organizationIds)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (object $organization): array => [
                'id' => (string) $organization->id,
                'name' => (string) $organization->name,
            ])->all());
    }

    /**
     * @param  list<string>  $facilityIds
     * @return list<array{id: string, organizationId: string, name: string}>
     */
    public function activeFacilities(string $tenantId, array $facilityIds): array
    {
        if ($facilityIds === []) {
            return [];
        }

        return array_values(DB::table('org_facilities')
            ->where('tenant_id', $tenantId)
            ->where('status', 'ACTIVE')
            ->whereIn('id', $facilityIds)
            ->orderBy('name')
            ->get(['id', 'organization_id', 'name'])
            ->map(static fn (object $row): array => [
                'id' => (string) $row->id,
                'organizationId' => (string) $row->organization_id,
                'name' => (string) $row->name,
            ])->all());
    }

    /**
     * @param  list<string>  $facilityIds
     * @return list<array<string, mixed>>
     */
    public function facilitySummaries(string $tenantId, array $facilityIds): array
    {
        if ($facilityIds === []) {
            return [];
        }

        return array_values(DB::table('org_facilities as facility')
            ->join('org_organizations as organization', 'organization.id', '=', 'facility.organization_id')
            ->leftJoinSub(
                DB::table('org_facility_capabilities')
                    ->select('facility_id')
                    ->selectRaw('count(*) as capability_count')
                    ->selectRaw("count(*) filter (where state = 'ENABLED') as enabled_capability_count")
                    ->groupBy('facility_id'),
                'capabilities',
                'capabilities.facility_id',
                '=',
                'facility.id',
            )
            ->where('facility.tenant_id', $tenantId)
            ->whereIn('facility.id', $facilityIds)
            ->orderBy('facility.name')
            ->get([
                'facility.id',
                'facility.name',
                'facility.code',
                'facility.facility_type',
                'facility.care_level',
                'facility.ownership_type',
                'facility.status',
                'organization.name as organization_name',
                DB::raw('coalesce(capabilities.capability_count, 0) as capability_count'),
                DB::raw('coalesce(capabilities.enabled_capability_count, 0) as enabled_capability_count'),
            ])
            ->map(static fn (object $facility): array => [
                'id' => (string) $facility->id,
                'name' => (string) $facility->name,
                'code' => (string) $facility->code,
                'type' => (string) $facility->facility_type,
                'careLevel' => (string) $facility->care_level,
                'ownership' => (string) $facility->ownership_type,
                'status' => (string) $facility->status,
                'organizationName' => (string) $facility->organization_name,
                'capabilityCount' => (int) $facility->capability_count,
                'enabledCapabilityCount' => (int) $facility->enabled_capability_count,
            ])->all());
    }

    /** @return array<string, mixed>|null */
    public function facilityPanel(string $tenantId, string $facilityId): ?array
    {
        $facility = DB::table('org_facilities as facility')
            ->join('org_organizations as organization', 'organization.id', '=', 'facility.organization_id')
            ->leftJoinSub(
                DB::table('org_facility_capabilities')
                    ->select('facility_id')
                    ->selectRaw('count(*) as capability_count')
                    ->selectRaw("count(*) filter (where state = 'ENABLED') as enabled_capability_count")
                    ->groupBy('facility_id'),
                'capabilities',
                'capabilities.facility_id',
                '=',
                'facility.id',
            )
            ->where('facility.tenant_id', $tenantId)
            ->where('facility.id', $facilityId)
            ->first([
                'facility.id',
                'facility.name',
                'facility.code',
                'facility.facility_type',
                'facility.care_level',
                'facility.ownership_type',
                'facility.status',
                'organization.name as organization_name',
                'organization.status as organization_status',
                DB::raw('coalesce(capabilities.capability_count, 0) as capability_count'),
                DB::raw('coalesce(capabilities.enabled_capability_count, 0) as enabled_capability_count'),
            ]);

        if ($facility === null) {
            return null;
        }

        $departments = DB::table('org_departments')
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'kind', 'status'])
            ->map(static fn (object $department): array => [
                'id' => (string) $department->id,
                'name' => (string) $department->name,
                'code' => (string) $department->code,
                'kind' => $department->kind === null ? null : (string) $department->kind,
                'status' => (string) $department->status,
            ])->all();
        $units = DB::table('org_units')
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->orderBy('name')
            ->get(['id', 'department_id', 'name', 'code', 'unit_type', 'status'])
            ->map(static fn (object $unit): array => [
                'id' => (string) $unit->id,
                'departmentId' => (string) $unit->department_id,
                'name' => (string) $unit->name,
                'code' => (string) $unit->code,
                'type' => (string) $unit->unit_type,
                'status' => (string) $unit->status,
            ])->all();
        $rooms = DB::table('org_rooms')
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->orderBy('name')
            ->get(['id', 'unit_id', 'name', 'code', 'room_type', 'status'])
            ->map(static fn (object $room): array => [
                'id' => (string) $room->id,
                'unitId' => $room->unit_id === null ? null : (string) $room->unit_id,
                'name' => (string) $room->name,
                'code' => (string) $room->code,
                'type' => (string) $room->room_type,
                'status' => (string) $room->status,
            ])->all();
        $capabilities = DB::table('org_capabilities')
            ->where('status', 'ACTIVE')
            ->orderBy('category')
            ->orderBy('code')
            ->get(['code', 'name', 'category'])
            ->map(static function (object $capability) use ($tenantId, $facilityId): array {
                $state = DB::table('org_facility_capabilities')
                    ->where('tenant_id', $tenantId)
                    ->where('facility_id', $facilityId)
                    ->where('capability_code', $capability->code)
                    ->first(['state', 'suspended_reason']);

                return [
                    'code' => (string) $capability->code,
                    'name' => (string) $capability->name,
                    'category' => (string) $capability->category,
                    'state' => $state === null ? null : (string) $state->state,
                    'suspendedReason' => $state === null || $state->suspended_reason === null
                        ? null
                        : (string) $state->suspended_reason,
                ];
            })->all();

        $facilityIsActive = (string) $facility->status === 'ACTIVE'
            && (string) $facility->organization_status === 'ACTIVE';

        return [
            'facility' => [
                'id' => (string) $facility->id,
                'name' => (string) $facility->name,
                'code' => (string) $facility->code,
                'type' => (string) $facility->facility_type,
                'careLevel' => (string) $facility->care_level,
                'ownership' => (string) $facility->ownership_type,
                'status' => (string) $facility->status,
                'organizationName' => (string) $facility->organization_name,
                'capabilityCount' => (int) $facility->capability_count,
                'enabledCapabilityCount' => (int) $facility->enabled_capability_count,
            ],
            'departments' => $departments,
            'units' => $units,
            'rooms' => $rooms,
            'capabilities' => $capabilities,
            'facilityIsActive' => $facilityIsActive,
        ];
    }

    /** @return list<array{code: string, name: string}> */
    public function facilityTypes(): array
    {
        return array_values(DB::table('org_facility_types')->where('active', true)->orderBy('name')
            ->get(['code', 'name'])->map(static fn (object $row): array => [
                'code' => (string) $row->code,
                'name' => (string) $row->name,
            ])->all());
    }

    /** @return list<array{code: string, name: string}> */
    public function careLevels(): array
    {
        return array_values(DB::table('org_care_levels')->where('active', true)->orderBy('rank')
            ->get(['code', 'name'])->map(static fn (object $row): array => [
                'code' => (string) $row->code,
                'name' => (string) $row->name,
            ])->all());
    }

    /** @return list<array{code: string, name: string}> */
    public function ownershipTypes(): array
    {
        return array_values(DB::table('org_ownership_types')->orderBy('name')
            ->get(['code', 'name'])->map(static fn (object $row): array => [
                'code' => (string) $row->code,
                'name' => (string) $row->name,
            ])->all());
    }

    /** @return list<string> */
    public function activeOrganizationIds(string $tenantId): array
    {
        return array_values(DB::table('org_organizations')->where('tenant_id', $tenantId)->where('status', 'ACTIVE')
            ->orderBy('id')->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all());
    }

    public function activeFacilityInOrganization(string $tenantId, string $facilityId): bool
    {
        return DB::table('org_facilities as facility')
            ->join('org_organizations as organization', 'organization.id', '=', 'facility.organization_id')
            ->where('facility.tenant_id', $tenantId)->where('facility.id', $facilityId)
            ->where('facility.status', 'ACTIVE')->where('organization.status', 'ACTIVE')->exists();
    }

    /** @return array{id: string, name: string, organizationId: string}|null */
    public function activeFacilitySummary(string $tenantId, string $facilityId): ?array
    {
        $facility = DB::table('org_facilities')
            ->where('tenant_id', $tenantId)
            ->where('id', $facilityId)
            ->where('status', 'ACTIVE')
            ->first(['id', 'name', 'organization_id']);

        return $facility === null
            ? null
            : [
                'id' => (string) $facility->id,
                'name' => (string) $facility->name,
                'organizationId' => (string) $facility->organization_id,
            ];
    }

    public function activeDepartmentInFacility(string $tenantId, string $facilityId, string $departmentId): bool
    {
        return DB::table('org_departments as department')
            ->join('org_facilities as facility', 'facility.id', '=', 'department.facility_id')
            ->join('org_organizations as organization', 'organization.id', '=', 'facility.organization_id')
            ->where('department.tenant_id', $tenantId)->where('department.id', $departmentId)
            ->where('department.facility_id', $facilityId)->where('department.status', 'ACTIVE')
            ->where('facility.status', 'ACTIVE')->where('organization.status', 'ACTIVE')->exists();
    }

    public function activeUnitInFacility(string $tenantId, string $facilityId, string $unitId): bool
    {
        return DB::table('org_units as unit')
            ->join('org_departments as department', 'department.id', '=', 'unit.department_id')
            ->join('org_facilities as facility', 'facility.id', '=', 'unit.facility_id')
            ->join('org_organizations as organization', 'organization.id', '=', 'facility.organization_id')
            ->where('unit.tenant_id', $tenantId)->where('unit.id', $unitId)
            ->where('unit.facility_id', $facilityId)->where('unit.status', 'ACTIVE')
            ->where('department.status', 'ACTIVE')->where('facility.status', 'ACTIVE')
            ->where('organization.status', 'ACTIVE')->exists();
    }

    public function activeUnitInDepartment(
        string $tenantId,
        string $facilityId,
        string $departmentId,
        string $unitId,
    ): bool {
        return DB::table('org_units as unit')
            ->join('org_departments as department', 'department.id', '=', 'unit.department_id')
            ->join('org_facilities as facility', 'facility.id', '=', 'unit.facility_id')
            ->join('org_organizations as organization', 'organization.id', '=', 'facility.organization_id')
            ->where('unit.tenant_id', $tenantId)
            ->where('unit.id', $unitId)
            ->where('unit.facility_id', $facilityId)
            ->where('unit.department_id', $departmentId)
            ->where('unit.status', 'ACTIVE')
            ->where('department.status', 'ACTIVE')
            ->where('facility.status', 'ACTIVE')
            ->where('organization.status', 'ACTIVE')
            ->exists();
    }

    /** @return list<array{id: string, name: string}> */
    public function activeDepartments(string $tenantId, string $facilityId): array
    {
        return array_values(DB::table('org_departments')
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->where('status', 'ACTIVE')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (object $row): array => [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
            ])->all());
    }

    /** @return list<array{id: string, departmentId: string, name: string}> */
    public function activeUnits(string $tenantId, string $facilityId): array
    {
        return array_values(DB::table('org_units')
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->where('status', 'ACTIVE')
            ->orderBy('name')
            ->get(['id', 'department_id', 'name'])
            ->map(static fn (object $row): array => [
                'id' => (string) $row->id,
                'departmentId' => (string) $row->department_id,
                'name' => (string) $row->name,
            ])->all());
    }

    public function facilityName(string $tenantId, string $facilityId): ?string
    {
        $name = DB::table('org_facilities')
            ->where('tenant_id', $tenantId)
            ->where('id', $facilityId)
            ->value('name');

        return $name === null ? null : (string) $name;
    }
}
