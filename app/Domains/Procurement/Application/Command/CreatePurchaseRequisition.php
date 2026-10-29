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
 * Raise a purchase requisition as DRAFT (19-procurement-domain.md §18–§21;
 * ADR-171 D-F). `$requisitionId` is client-minted (UUIDv7). `>=1` line;
 * `emergency` requires a non-blank `emergencyReason` (F15) and the actor to
 * hold `procurement.emergency.manage` (checked in the handler).
 *
 * @param  array<int, array<string, mixed>>  $lines
 */
final readonly class CreatePurchaseRequisition implements Auditable, AuthorizesAgainst
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
        public string $priority = 'ROUTINE',
        public ?string $requestingDepartmentRef = null,
        public ?string $reason = null,
        public ?string $estimatedValue = null,
        public ?string $costCentreRef = null,
        public ?string $budgetRef = null,
        public bool $emergency = false,
        public ?string $emergencyReason = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['requisitionId' => $requisitionId, 'actorId' => $actorId, 'facilityId' => $facilityId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("CreatePurchaseRequisition::\${$name} must be a UUID (IC §3).");
            }
        }

        $lines = array_values($lines);
        if ($lines === []) {
            throw ProcurementLineSetInvalid::empty('purchase requisition');
        }

        if (trim($priority) === '') {
            throw new InvalidArgumentException('CreatePurchaseRequisition::$priority must not be blank.');
        }

        if ($emergency && ($emergencyReason === null || trim($emergencyReason) === '')) {
            throw new InvalidArgumentException('CreatePurchaseRequisition::$emergencyReason is required when $emergency is true (§27; ADR-171 F15).');
        }

        if ($estimatedValue !== null && ! is_numeric($estimatedValue)) {
            throw new InvalidArgumentException('CreatePurchaseRequisition::$estimatedValue must be numeric or null.');
        }

        $this->lines = $lines;
    }

    public function policyAbility(): string
    {
        return 'create';
    }

    public function subjectId(): ?string
    {
        return null;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'procurement.requisition_created',
            actorId: $this->actorId,
            targetType: 'purchase_requisition',
            targetId: $this->requisitionId,
            metadata: [
                'facility_id' => $this->facilityId,
                'department_ref' => $this->requestingDepartmentRef,
                'line_count' => count($this->lines),
                'emergency' => $this->emergency,
                'cost_centre_ref' => $this->costCentreRef,
                'budget_ref' => $this->budgetRef,
            ],
        );
    }
}
