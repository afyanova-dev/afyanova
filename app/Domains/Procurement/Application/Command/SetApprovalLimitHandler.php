<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Domains\Organization\Contracts\OrganizationApi;
use App\Domains\Procurement\Domain\ApprovalPolicyRepository;
use App\Domains\Procurement\Domain\Model\ApprovalPolicy;
use App\Shared\Audit\AuditLogger;
use InvalidArgumentException;

/**
 * Apply a `SetApprovalLimit` (Shape B).
 *
 * Writes exactly ONE row: the scope's own. `$limit === null` removes it so the
 * wider scope applies again. The same action names as
 * `procurement:configure-approval-limit`, so the audit trail reads the same
 * whether an administrator used the screen or the server.
 */
final class SetApprovalLimitHandler
{
    public function __construct(
        private readonly ApprovalPolicyRepository $policies,
        private readonly OrganizationApi $organization,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(SetApprovalLimit $command): void
    {
        if ($command->departmentRef !== null
            && ! $this->organization->departmentExists($command->facilityId, $command->departmentRef)) {
            throw new InvalidArgumentException('The department does not belong to that facility.');
        }

        $row = $this->policies->findFor($command->facilityId, $command->departmentRef);

        if ($command->limit === null) {
            if ($row === null) {
                return;
            }

            $this->policies->delete($row);

            $this->audit->record(
                action: 'procurement.approval_limit_cleared',
                actorId: $command->actorId,
                targetType: 'approval_policy',
                targetId: (string) $row->id,
                metadata: [
                    'facility_id' => $command->facilityId,
                    'department_ref' => $command->departmentRef,
                ],
            );

            return;
        }

        $created = $row === null;

        if ($row === null) {
            $row = ApprovalPolicy::define($command->facilityId, $command->departmentRef, $command->limit);
        } else {
            $row->reviseLimit($command->limit);
        }

        $this->policies->save($row);

        $this->audit->record(
            action: 'procurement.approval_limit_configured',
            actorId: $command->actorId,
            targetType: 'approval_policy',
            targetId: (string) $row->id,
            metadata: [
                'facility_id' => $command->facilityId,
                'department_ref' => $command->departmentRef,
                'self_approval_limit' => (string) $row->self_approval_limit,
                'created' => $created,
            ],
        );
    }
}
