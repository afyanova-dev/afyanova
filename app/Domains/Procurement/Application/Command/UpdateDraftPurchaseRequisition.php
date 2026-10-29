<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Domains\Procurement\Domain\Exception\ProcurementLineSetInvalid;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Replace the requester-editable contents of a DRAFT requisition.
 *
 * @param  array<int, array<string, mixed>>  $lines
 */
final readonly class UpdateDraftPurchaseRequisition implements Auditable, AuthorizesAgainst
{
    /** @var list<array<string, mixed>> */
    public array $lines;

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function __construct(
        public string $requisitionId,
        public string $actorId,
        public string $facilityId,
        array $lines,
        public string $priority,
        public ?string $requestingDepartmentRef,
        public ?string $reason,
        public ?string $costCentreRef = null,
        public ?string $budgetRef = null,
    ) {
        foreach (['requisitionId' => $requisitionId, 'actorId' => $actorId, 'facilityId' => $facilityId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException('UpdateDraftPurchaseRequisition::$'.$name.' must be a UUID (IC §3).');
            }
        }

        $lines = array_values($lines);
        if ($lines === []) {
            throw ProcurementLineSetInvalid::empty('purchase requisition');
        }
        if (! in_array(strtoupper(trim($priority)), ['ROUTINE', 'URGENT'], true)) {
            throw new InvalidArgumentException('UpdateDraftPurchaseRequisition::$priority must be ROUTINE or URGENT.');
        }

        $this->lines = $lines;
    }

    public function policyAbility(): string
    {
        return 'update';
    }

    public function subjectId(): string
    {
        return $this->requisitionId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'procurement.requisition_draft_updated',
            actorId: $this->actorId,
            targetType: 'purchase_requisition',
            targetId: $this->requisitionId,
            metadata: [
                'facility_id' => $this->facilityId,
                'department_ref' => $this->requestingDepartmentRef,
                'line_count' => count($this->lines),
                'cost_centre_ref' => $this->costCentreRef,
                'budget_ref' => $this->budgetRef,
            ],
        );
    }
}
