<?php

declare(strict_types=1);

namespace App\Domains\HR\Infrastructure\Reader;

use Illuminate\Support\Facades\DB;

final class AssignmentRequestReader
{
    /** @return array<string, mixed>|null */
    public function pendingForDecision(string $tenantId, string $facilityId, string $requestId): ?array
    {
        $row = DB::table('hr_assignment_requests')
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->where('id', $requestId)
            ->where('status', 'PENDING')
            ->lockForUpdate()
            ->first();

        return $row === null ? null : (array) $row;
    }

    /** @return array<string, mixed>|null */
    public function rejectedByRequester(
        string $tenantId,
        string $facilityId,
        string $requestId,
        string $requesterId,
    ): ?array {
        $row = DB::table('hr_assignment_requests')
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->where('id', $requestId)
            ->where('requested_by', $requesterId)
            ->where('status', 'REJECTED')
            ->lockForUpdate()
            ->first();

        return $row === null ? null : (array) $row;
    }
}
