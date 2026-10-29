<?php

declare(strict_types=1);

use App\Domains\Organization\Domain\Model\Facility;
use App\Domains\Organization\Domain\Model\Organization;
use App\Domains\Organization\Domain\Model\Room;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\Queueing\Domain\Enum\QueueOperatingStatus;
use App\Platform\Queueing\Domain\Enum\QueuePriority;
use App\Platform\Queueing\Domain\Enum\ServiceCapability;
use App\Platform\Queueing\Domain\Model\Queue;
use App\Platform\Queueing\Domain\Model\QueueEntry;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContextScope;

require_once __DIR__.'/security.php';

if (! function_exists('queueingFixture')) {
    /**
     * @param  list<string>|null  $permissions
     * @return array{tenant: Tenant, organization: Organization, facility: Facility, user: User, role_id: string}
     */
    function queueingFixture(?array $permissions = null): array
    {
        $permissions ??= [
            'queue.access',
            'queue.ticket.view',
            'queue.ticket.place',
            'queue.ticket.call',
            'queue.ticket.service',
            'queue.ticket.hold',
            'queue.ticket.transfer',
            'queue.definition.manage',
        ];

        /** @var array{tenant: Tenant, organization: Organization, facility: Facility, user: User, role_id: string} $fixture */
        $fixture = makeTenantWithUser($permissions);
        test()->actingAs($fixture['user']);

        return $fixture;
    }
}

if (! function_exists('queueTestQueue')) {
    function queueTestQueue(string $tenantId, string $facilityId, string $code = 'TST'): Queue
    {
        return TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId, $code): Queue {
            $normalized = strtoupper($code);
            $serviceCapability = null;

            if (str_contains($normalized, 'TRIAGE') || str_contains($normalized, 'NURSE')) {
                $serviceCapability = ServiceCapability::TRIAGE->value;
            } elseif (str_contains($normalized, 'CONSULT') || str_contains($normalized, 'CLINIC')) {
                $serviceCapability = ServiceCapability::CONSULTATION->value;
            } elseif (str_contains($normalized, 'PROC') || str_contains($normalized, 'PROCEDURE')) {
                $serviceCapability = ServiceCapability::PROCEDURE->value;
            } elseif (str_contains($normalized, 'PHARM')) {
                $serviceCapability = ServiceCapability::PHARMACY->value;
            } elseif (str_contains($normalized, 'LAB')) {
                $serviceCapability = ServiceCapability::LABORATORY->value;
            } elseif (str_contains($normalized, 'RAD')) {
                $serviceCapability = ServiceCapability::RADIOLOGY->value;
            } elseif (str_contains($normalized, 'CASH')) {
                $serviceCapability = ServiceCapability::CASHIER->value;
            }

            $queue = new Queue([
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'code' => $code.'-'.strtoupper(Str::random(5)),
                'name' => 'Queue Test Queue',
                'service_capability' => $serviceCapability,
                'ticket_prefix' => 'Q',
                'is_active' => true,
                'operating_status' => QueueOperatingStatus::ACTIVE,
            ]);
            $queue->save();

            return $queue;
        });
    }
}

if (! function_exists('queueTestEntry')) {
    function queueTestEntry(string $tenantId, string $facilityId, string $queueId): QueueEntry
    {
        return TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId, $queueId): QueueEntry {
            $entry = QueueEntry::place(
                tenantId: $tenantId,
                facilityId: $facilityId,
                queueId: $queueId,
                encounterId: (string) Str::uuid7(),
                patientId: (string) Str::uuid7(),
                ticketNumber: 'Q-'.strtoupper(Str::random(5)),
                priority: QueuePriority::ROUTINE,
            );
            $entry->save();

            return $entry;
        });
    }
}

if (! function_exists('queueTestSecondFacility')) {
    function queueTestSecondFacility(string $tenantId, string $orgId, string $userId, string $roleId): Facility
    {
        return TenantContextScope::run($tenantId, null, function () use ($tenantId, $orgId, $userId, $roleId): Facility {
            $facility = Facility::factory()->forTenant($tenantId, $orgId)->create();

            DB::table('idn_access_assignments')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'role_id' => $roleId,
                'facility_id' => $facility->id,
                'status' => 'ACTIVE',
                'is_primary' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $facility;
        });
    }
}

if (! function_exists('queueTestRoom')) {
    function queueTestRoom(string $tenantId, string $facilityId, string $code = 'CONS-1'): Room
    {
        return TenantContextScope::run($tenantId, $facilityId, fn (): Room => Room::query()->create([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'name' => $code === 'CONS-1' ? 'Consult Room 1' : $code,
            'code' => $code,
        ]));
    }
}

if (! function_exists('queueAssignRoom')) {
    function queueAssignRoom(string $tenantId, string $facilityId, string $queueId, string $roomId): void
    {
        TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId, $queueId, $roomId): void {
            DB::table('qms_queue_rooms')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'queue_id' => $queueId,
                'room_id' => $roomId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }
}

if (! function_exists('queueAssignRoomStaff')) {
    function queueAssignRoomStaff(
        string $tenantId,
        string $facilityId,
        string $queueId,
        string $roomId,
        string $userId,
    ): void {
        TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId, $queueId, $roomId, $userId): void {
            DB::table('qms_queue_room_staff')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'queue_id' => $queueId,
                'room_id' => $roomId,
                'user_id' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }
}
