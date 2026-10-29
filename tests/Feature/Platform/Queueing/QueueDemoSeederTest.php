<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Queueing;

use App\Models\Tenant;
use App\Shared\Tenancy\TenantContextScope;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('the demo seeder creates Tanzania-relevant hospital queues with matching service rooms', function (): void {
    $this->seed(DatabaseSeeder::class);

    $tenantId = (string) Tenant::query()->where('slug', 'afyanova-demo')->value('id');

    $queueRooms = TenantContextScope::run($tenantId, null, function (): array {
        $hospitalId = (string) DB::table('org_facilities')->where('code', 'DEMO_HOSP')->value('id');

        return DB::table('qms_queues')
            ->join('qms_queue_rooms', 'qms_queue_rooms.queue_id', '=', 'qms_queues.id')
            ->join('org_rooms', 'org_rooms.id', '=', 'qms_queue_rooms.room_id')
            ->where('qms_queues.facility_id', $hospitalId)
            ->where('qms_queues.is_active', true)
            ->orderBy('qms_queues.code')
            ->orderBy('org_rooms.code')
            ->get(['qms_queues.code as queue_code', 'org_rooms.code as room_code'])
            ->mapToGroups(static fn (object $row): array => [$row->queue_code => $row->room_code])
            ->map(static fn ($roomCodes): array => $roomCodes->all())
            ->all();
    });

    expect($queueRooms)->toMatchArray([
        'TRG' => ['OPD_TRIAGE_1'],
        'OPD' => ['OPD_1', 'OPD_2'],
        'PROC' => ['OPD_PROC_1'],
        'EMERG' => ['TRIAGE_1'],
        'RESUS' => ['BAY_1', 'BAY_2'],
        'LAB' => ['COLLECTION_1'],
        'PHARM' => ['COUNTER_1', 'COUNTER_2'],
        'CASH' => ['CASHIER_1'],
    ]);
    expect($queueRooms)->toHaveKeys(['EMERG', 'RESUS'])
        ->and($queueRooms)->not->toHaveKey('RCH')
        ->and($queueRooms)->not->toHaveKey('MAT');

    $outpatientUnits = TenantContextScope::run($tenantId, null, static function (): array {
        $outpatientDepartmentId = (string) DB::table('org_departments')
            ->where('code', 'OUTPATIENT')
            ->value('id');

        return DB::table('org_units')
            ->where('department_id', $outpatientDepartmentId)
            ->orderBy('code')
            ->pluck('code')
            ->all();
    });

    expect($outpatientUnits)->toContain('OPD_TRIAGE', 'CONSULTATION', 'PROCEDURES')
        ->and($outpatientUnits)->not->toContain('RCH_CLINIC');

    $procedureStaffAssignment = TenantContextScope::run($tenantId, null, static function () use ($tenantId): bool {
        $hospitalId = (string) DB::table('org_facilities')->where('code', 'DEMO_HOSP')->value('id');
        $nurseId = (string) DB::table('users')
            ->where('tenant_id', $tenantId)
            ->where('email', 'nurse@afyanova.local')
            ->value('id');

        return DB::table('qms_queue_room_staff as staff')
            ->join('qms_queues as queue', 'queue.id', '=', 'staff.queue_id')
            ->join('org_rooms as room', 'room.id', '=', 'staff.room_id')
            ->where('staff.facility_id', $hospitalId)
            ->where('staff.user_id', $nurseId)
            ->where('queue.code', 'PROC')
            ->where('room.code', 'OPD_PROC_1')
            ->exists();
    });

    expect($procedureStaffAssignment)->toBeTrue();

    $this->seed(DatabaseSeeder::class);

    $hospitalQueueCount = TenantContextScope::run($tenantId, null, static function (): int {
        $hospitalId = (string) DB::table('org_facilities')->where('code', 'DEMO_HOSP')->value('id');

        return DB::table('qms_queues')
            ->where('facility_id', $hospitalId)
            ->where('is_active', true)
            ->count();
    });

    expect($hospitalQueueCount)->toBe(8);
});
