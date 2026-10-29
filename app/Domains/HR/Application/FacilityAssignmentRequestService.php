<?php

declare(strict_types=1);

namespace App\Domains\HR\Application;

use App\Domains\HR\Application\Command\AssignStaff;
use App\Domains\HR\Domain\ValueObject\AssignmentScope;
use App\Domains\HR\Infrastructure\Reader\AssignmentRequestReader;
use App\Domains\HR\Infrastructure\Writer\AssignmentRequestWriter;
use App\Domains\Organization\Contracts\OrganizationAdminApi;
use App\Shared\Audit\AuditLogger;
use App\Shared\Bus\CommandBus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class FacilityAssignmentRequestService
{
    public function __construct(
        private readonly AssignmentRequestReader $reader,
        private readonly AssignmentRequestWriter $writer,
        private readonly OrganizationAdminApi $organization,
        private readonly CommandBus $commands,
        private readonly AuditLogger $audit,
    ) {}

    public function create(
        string $requestId,
        string $tenantId,
        string $staffId,
        string $facilityId,
        ?string $departmentId,
        ?string $unitId,
        AssignmentScope $scope,
        string $effectiveFrom,
        string $requesterId,
    ): void {
        $this->writer->create(
            $requestId,
            $tenantId,
            $staffId,
            $facilityId,
            $departmentId,
            $unitId,
            $scope->value,
            $effectiveFrom,
            $requesterId,
        );
    }

    public function decide(
        string $tenantId,
        string $facilityId,
        string $requestId,
        string $actorId,
        string $decision,
        ?string $reason,
    ): void {
        DB::transaction(function () use (
            $tenantId,
            $facilityId,
            $requestId,
            $actorId,
            $decision,
            $reason,
        ): void {
            $row = $this->reader->pendingForDecision($tenantId, $facilityId, $requestId);
            abort_unless($row !== null, 404);

            if ($decision === 'approve') {
                abort_unless(
                    $this->organization->activeFacilitySummary($tenantId, $facilityId) !== null,
                    409,
                    'The facility is no longer active.',
                );
                if ($row['department_id'] !== null) {
                    abort_unless(
                        $this->organization->activeDepartmentInFacility(
                            $tenantId,
                            $facilityId,
                            (string) $row['department_id'],
                        ),
                        409,
                        'The requested department is no longer active in this facility.',
                    );
                }
                if ($row['unit_id'] !== null) {
                    abort_unless(
                        $row['department_id'] !== null
                            && $this->organization->activeUnitInDepartment(
                                $tenantId,
                                $facilityId,
                                (string) $row['department_id'],
                                (string) $row['unit_id'],
                            ),
                        409,
                        'The requested unit is no longer active in this department.',
                    );
                }
                $this->commands->dispatch(new AssignStaff(
                    staffId: (string) $row['staff_id'],
                    facilityId: $facilityId,
                    departmentId: $row['department_id'] === null ? null : (string) $row['department_id'],
                    unitId: $row['unit_id'] === null ? null : (string) $row['unit_id'],
                    scope: AssignmentScope::from((string) $row['scope']),
                    effectiveFrom: CarbonImmutable::parse((string) $row['effective_from']),
                    isPrimary: false,
                ));
            }

            $status = $decision === 'approve' ? 'APPROVED' : 'REJECTED';
            $this->writer->decide((string) $row['id'], $status, $actorId, $reason);
            $staffId = (string) $row['staff_id'];
            $this->audit->recordFor(
                tenantId: $tenantId,
                action: $decision === 'approve'
                    ? 'hr.staff_assignment.approved'
                    : 'hr.staff_assignment.rejected',
                actorId: $actorId,
                facilityId: $facilityId,
                reason: $reason,
                metadata: ['request' => (string) $row['id'], 'staff' => $staffId],
                targetType: 'staff',
                targetId: $staffId,
            );
        });
    }

    public function resubmit(
        string $tenantId,
        string $facilityId,
        string $requestId,
        string $requesterId,
        ?string $departmentId,
        ?string $unitId,
        AssignmentScope $scope,
        string $effectiveFrom,
    ): void {
        DB::transaction(function () use (
            $tenantId,
            $facilityId,
            $requestId,
            $requesterId,
            $departmentId,
            $unitId,
            $scope,
            $effectiveFrom,
        ): void {
            $row = $this->reader->rejectedByRequester($tenantId, $facilityId, $requestId, $requesterId);
            abort_unless($row !== null, 404);

            $this->writer->resubmit((string) $row['id'], $departmentId, $unitId, $scope->value, $effectiveFrom);
            $staffId = (string) $row['staff_id'];
            $this->audit->recordFor(
                tenantId: $tenantId,
                action: 'hr.staff_assignment.resubmitted',
                actorId: $requesterId,
                facilityId: $facilityId,
                metadata: ['request' => (string) $row['id'], 'staff' => $staffId],
                targetType: 'staff',
                targetId: $staffId,
            );
        });
    }
}
