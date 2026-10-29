<?php

declare(strict_types=1);

namespace App\Domains\HR\Infrastructure\Writer;

use Illuminate\Support\Facades\DB;

final class AssignmentRequestWriter
{
    public function create(
        string $id,
        string $tenantId,
        string $staffId,
        string $facilityId,
        ?string $departmentId,
        ?string $unitId,
        string $scope,
        string $effectiveFrom,
        string $requesterId,
    ): void {
        DB::table('hr_assignment_requests')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'staff_id' => $staffId,
            'facility_id' => $facilityId,
            'department_id' => $departmentId,
            'unit_id' => $unitId,
            'scope' => $scope,
            'effective_from' => $effectiveFrom,
            'requested_by' => $requesterId,
            'status' => 'PENDING',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function decide(
        string $requestId,
        string $status,
        string $actorId,
        ?string $reason,
    ): void {
        DB::table('hr_assignment_requests')
            ->where('id', $requestId)
            ->update([
                'status' => $status,
                'decided_by' => $actorId,
                'decided_at' => now(),
                'decision_reason' => $reason,
                'updated_at' => now(),
            ]);
    }

    public function resubmit(
        string $requestId,
        ?string $departmentId,
        ?string $unitId,
        string $scope,
        string $effectiveFrom,
    ): void {
        DB::table('hr_assignment_requests')
            ->where('id', $requestId)
            ->update([
                'department_id' => $departmentId,
                'unit_id' => $unitId,
                'scope' => $scope,
                'effective_from' => $effectiveFrom,
                'status' => 'PENDING',
                'decided_by' => null,
                'decided_at' => null,
                'decision_reason' => null,
                'updated_at' => now(),
            ]);
    }
}
