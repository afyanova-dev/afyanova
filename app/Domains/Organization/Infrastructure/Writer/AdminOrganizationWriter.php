<?php

declare(strict_types=1);

namespace App\Domains\Organization\Infrastructure\Writer;

use App\Domains\Organization\Domain\Model\Department;
use App\Domains\Organization\Domain\Model\Facility;
use App\Domains\Organization\Domain\Model\FacilityCapability;
use App\Domains\Organization\Domain\Model\Room;
use App\Domains\Organization\Domain\Model\Unit;
use App\Shared\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class AdminOrganizationWriter
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function createDepartment(string $tenantId, string $facilityId, string $name, string $code, ?string $kind, string $actorId): string
    {
        return DB::transaction(function () use ($tenantId, $facilityId, $name, $code, $kind, $actorId): string {
            $this->lockActiveFacility($tenantId, $facilityId);
            $department = Department::query()->create([
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'name' => $name,
                'code' => $code,
                'kind' => $kind,
                'status' => 'ACTIVE',
            ]);
            $id = (string) $department->getKey();

            $this->audit->recordFor(
                tenantId: $tenantId,
                action: 'organization.structure.department_created',
                actorId: $actorId,
                facilityId: $facilityId,
                metadata: ['code' => $code, 'name' => $name, 'kind' => $kind, 'status' => 'ACTIVE'],
                targetType: 'department',
                targetId: $id,
            );

            return $id;
        });
    }

    public function createUnit(string $tenantId, string $facilityId, string $departmentId, string $name, string $code, string $unitType, string $actorId): string
    {
        return DB::transaction(function () use ($tenantId, $facilityId, $departmentId, $name, $code, $unitType, $actorId): string {
            $this->lockActiveFacility($tenantId, $facilityId);
            $department = DB::table('org_departments')
                ->where('tenant_id', $tenantId)
                ->where('id', $departmentId)
                ->where('facility_id', $facilityId)
                ->where('status', 'ACTIVE')
                ->lockForUpdate()
                ->exists();
            abort_unless($department, 422, 'The department is no longer active in this facility.');

            $unit = Unit::query()->create([
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'department_id' => $departmentId,
                'name' => $name,
                'code' => $code,
                'unit_type' => $unitType,
                'status' => 'ACTIVE',
            ]);
            $id = (string) $unit->getKey();

            $this->audit->recordFor(
                tenantId: $tenantId,
                action: 'organization.structure.unit_created',
                actorId: $actorId,
                facilityId: $facilityId,
                metadata: [
                    'code' => $code,
                    'name' => $name,
                    'unit_type' => $unitType,
                    'department_id' => $departmentId,
                    'status' => 'ACTIVE',
                ],
                targetType: 'unit',
                targetId: $id,
            );

            return $id;
        });
    }

    public function createFacility(string $tenantId, string $organizationId, string $name, string $code, string $facilityType, string $careLevel, string $ownershipType, string $actorId): string
    {
        return DB::transaction(function () use ($tenantId, $organizationId, $name, $code, $facilityType, $careLevel, $ownershipType, $actorId): string {
            $organizationIsActive = DB::table('org_organizations')
                ->where('tenant_id', $tenantId)
                ->where('id', $organizationId)
                ->where('status', 'ACTIVE')
                ->lockForUpdate()
                ->exists();
            abort_unless($organizationIsActive, 422, 'The organization is no longer active.');

            $facility = Facility::query()->create([
                'tenant_id' => $tenantId,
                'organization_id' => $organizationId,
                'name' => $name,
                'code' => $code,
                'facility_type' => $facilityType,
                'care_level' => $careLevel,
                'ownership_type' => $ownershipType,
                'status' => 'ACTIVE',
            ]);
            $id = (string) $facility->getKey();

            $this->audit->recordFor(
                tenantId: $tenantId,
                action: 'organization.facility.created',
                actorId: $actorId,
                facilityId: $id,
                metadata: [
                    'code' => $code,
                    'name' => $name,
                    'facility_type' => $facilityType,
                    'care_level' => $careLevel,
                    'ownership_type' => $ownershipType,
                    'status' => 'ACTIVE',
                ],
                targetType: 'facility',
                targetId: $id,
            );

            return $id;
        });
    }

    public function createRoom(string $tenantId, string $facilityId, ?string $unitId, string $name, string $code, string $roomType, string $actorId): string
    {
        return DB::transaction(function () use ($tenantId, $facilityId, $unitId, $name, $code, $roomType, $actorId): string {
            $this->lockActiveFacility($tenantId, $facilityId);
            if ($unitId !== null) {
                $unit = DB::table('org_units as unit')
                    ->join('org_departments as department', 'department.id', '=', 'unit.department_id')
                    ->where('unit.tenant_id', $tenantId)
                    ->where('unit.id', $unitId)
                    ->where('unit.facility_id', $facilityId)
                    ->where('unit.status', 'ACTIVE')
                    ->where('department.status', 'ACTIVE')
                    ->lockForUpdate()
                    ->exists();
                abort_unless($unit, 422, 'The unit is no longer active in this facility.');
            }

            $room = Room::query()->create([
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'unit_id' => $unitId,
                'name' => $name,
                'code' => $code,
                'room_type' => $roomType,
                'status' => 'ACTIVE',
            ]);
            $id = (string) $room->getKey();

            $this->audit->recordFor(
                tenantId: $tenantId,
                action: 'organization.structure.room_created',
                actorId: $actorId,
                facilityId: $facilityId,
                metadata: [
                    'code' => $code,
                    'name' => $name,
                    'room_type' => $roomType,
                    'unit_id' => $unitId,
                    'status' => 'ACTIVE',
                ],
                targetType: 'room',
                targetId: $id,
            );

            return $id;
        });
    }

    public function setFacilityCapabilityState(string $tenantId, string $facilityId, string $capabilityCode, string $state, ?string $reason, string $actorId): void
    {
        DB::transaction(function () use ($tenantId, $facilityId, $capabilityCode, $state, $reason, $actorId): void {
            $this->lockActiveFacility($tenantId, $facilityId);
            FacilityCapability::query()->updateOrCreate(
                ['facility_id' => $facilityId, 'capability_code' => $capabilityCode],
                [
                    'tenant_id' => $tenantId,
                    'state' => $state,
                    'enabled_at' => $state === 'ENABLED' ? now() : null,
                    'suspended_reason' => $reason,
                ],
            );
            $this->audit->recordFor(
                tenantId: $tenantId,
                action: 'organization.capability.'.strtolower($state),
                actorId: $actorId,
                facilityId: $facilityId,
                reason: $reason,
                metadata: ['capability' => $capabilityCode, 'state' => $state],
                targetType: 'facility',
                targetId: $facilityId,
            );
        });
    }

    /** @param array<string, mixed> $changes */
    public function updateStructureRecord(string $tenantId, string $facilityId, string $kind, string $id, array $changes, string $actorId): void
    {
        $config = $this->structureRecordConfig($kind);
        DB::transaction(function () use ($tenantId, $facilityId, $kind, $id, $changes, $actorId, $config): void {
            $record = DB::table($config['table'])
                ->where('tenant_id', $tenantId)
                ->where('facility_id', $facilityId)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();
            abort_unless($record !== null, 404);
            $this->lockActiveFacility($tenantId, $facilityId);

            if ($kind === 'unit' && (string) $record->department_id !== (string) $changes['department_id']) {
                $this->assertActiveDepartment($tenantId, $facilityId, (string) $changes['department_id']);
            } elseif ($kind === 'room' && ($changes['unit_id'] ?? null) !== null
                && (string) ($record->unit_id ?? '') !== (string) $changes['unit_id']) {
                $this->assertActiveUnit($tenantId, $facilityId, (string) $changes['unit_id']);
            }

            $safeChanges = array_intersect_key($changes, array_flip($config['columns']));
            DB::table($config['table'])->where('id', $id)->update([
                ...$safeChanges,
                'version' => DB::raw('version + 1'),
                'updated_at' => now(),
            ]);
            $this->audit->recordFor(
                tenantId: $tenantId,
                action: "organization.structure.{$kind}_updated",
                actorId: $actorId,
                facilityId: $facilityId,
                metadata: $safeChanges,
                targetType: $kind,
                targetId: $id,
            );
        });
    }

    public function setStructureRecordStatus(string $tenantId, string $facilityId, string $kind, string $id, string $status, string $actorId): void
    {
        $config = $this->structureRecordConfig($kind);
        DB::transaction(function () use ($tenantId, $facilityId, $kind, $id, $status, $actorId, $config): void {
            $record = DB::table($config['table'])
                ->where('tenant_id', $tenantId)
                ->where('facility_id', $facilityId)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();
            abort_unless($record !== null, 404);
            $this->lockActiveFacility($tenantId, $facilityId);

            if ($status === 'INACTIVE' && $kind !== 'room') {
                [$childrenTable, $foreignKey] = $kind === 'department'
                    ? ['org_units', 'department_id']
                    : ['org_rooms', 'unit_id'];
                abort_if(
                    DB::table($childrenTable)
                        ->where('tenant_id', $tenantId)
                        ->where('facility_id', $facilityId)
                        ->where($foreignKey, $id)
                        ->where('status', 'ACTIVE')
                        ->exists(),
                    422,
                    'Archive active child records before archiving this record.',
                );
            }

            if ($status === 'ACTIVE' && $kind !== 'department') {
                $parentTable = $kind === 'unit' ? 'org_departments' : 'org_units';
                $parentKey = $kind === 'unit' ? $record->department_id : $record->unit_id;
                if ($parentKey !== null) {
                    $parentIsActive = DB::table($parentTable)
                        ->where('tenant_id', $tenantId)
                        ->where('facility_id', $facilityId)
                        ->where('id', $parentKey)
                        ->where('status', 'ACTIVE')
                        ->exists();
                    abort_unless($parentIsActive, 422, 'Reactivate the parent record before reactivating this record.');
                }
            }

            DB::table($config['table'])->where('id', $id)->update([
                'status' => $status,
                'version' => DB::raw('version + 1'),
                'updated_at' => now(),
            ]);
            $this->audit->recordFor(
                tenantId: $tenantId,
                action: "organization.structure.{$kind}_".strtolower($status),
                actorId: $actorId,
                facilityId: $facilityId,
                metadata: ['status' => $status],
                targetType: $kind,
                targetId: $id,
            );
        });
    }

    private function lockActiveFacility(string $tenantId, string $facilityId): void
    {
        $facility = DB::table('org_facilities')
            ->where('tenant_id', $tenantId)
            ->where('id', $facilityId)
            ->where('status', 'ACTIVE')
            ->lockForUpdate()
            ->first(['organization_id']);
        abort_unless($facility !== null, 422, 'The facility is no longer active.');

        $organizationIsActive = DB::table('org_organizations')
            ->where('tenant_id', $tenantId)
            ->where('id', $facility->organization_id)
            ->where('status', 'ACTIVE')
            ->lockForUpdate()
            ->exists();
        abort_unless($organizationIsActive, 422, 'The organization is no longer active.');
    }

    private function assertActiveDepartment(string $tenantId, string $facilityId, string $departmentId): void
    {
        $active = DB::table('org_departments')
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->where('id', $departmentId)
            ->where('status', 'ACTIVE')
            ->exists();
        abort_unless($active, 422, 'Select an active department in this facility.');
    }

    private function assertActiveUnit(string $tenantId, string $facilityId, string $unitId): void
    {
        $active = DB::table('org_units')
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->where('id', $unitId)
            ->where('status', 'ACTIVE')
            ->exists();
        abort_unless($active, 422, 'Select an active unit in this facility.');
    }

    /** @return array{table: string, columns: list<string>} */
    private function structureRecordConfig(string $kind): array
    {
        return match ($kind) {
            'department' => ['table' => 'org_departments', 'columns' => ['name', 'code', 'kind']],
            'unit' => ['table' => 'org_units', 'columns' => ['name', 'code', 'department_id', 'unit_type']],
            'room' => ['table' => 'org_rooms', 'columns' => ['name', 'code', 'unit_id', 'room_type']],
            default => throw new InvalidArgumentException("Unknown organization structure kind [{$kind}]."),
        };
    }
}
