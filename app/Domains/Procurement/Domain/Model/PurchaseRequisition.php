<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Model;

use App\Domains\Procurement\Contracts\Event\PurchaseRequisitionApproved;
use App\Domains\Procurement\Database\Factories\PurchaseRequisitionFactory;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * The Purchase Requisition aggregate (19-procurement-domain.md §17–§24, §244;
 * ADR-171 D-E / D-F).
 *
 * §21 lifecycle FRONT only: DRAFT → SUBMITTED → APPROVED (+ REJECTED from
 * SUBMITTED, + CANCELLED from DRAFT|SUBMITTED). The SOURCING → ORDERED →
 * FULFILLED tail is NOT wired. Emits `PurchaseRequisitionApproved` on approval;
 * submit / reject / cancel are eventless status changes. Lines live on
 * `pcr_requisition_lines` (a plain child written by the create handler).
 *
 * @domain      Procurement
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @facility    required
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $facility_id
 * @property string $requisition_number
 * @property string|null $requesting_department_ref
 * @property string $priority
 * @property string|null $reason
 * @property string|null $estimated_value
 * @property string|null $cost_centre_ref
 * @property string|null $budget_ref
 * @property bool $emergency
 * @property string|null $emergency_reason
 * @property string $status
 * @property string|null $reviewed_by_user_id
 * @property CarbonImmutable|null $reviewed_at
 * @property string|null $rejection_reason
 * @property string $created_by_user_id
 * @property CarbonImmutable|null $occurred_at
 * @property CarbonImmutable|null $recorded_at
 * @property int $version
 */
final class PurchaseRequisition extends AggregateRoot
{
    /** @use HasFactory<PurchaseRequisitionFactory> */
    use HasFactory;

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_SUBMITTED = 'SUBMITTED';

    public const STATUS_APPROVED = 'APPROVED';

    public const STATUS_REJECTED = 'REJECTED';

    public const STATUS_CANCELLED = 'CANCELLED';

    protected $table = 'pcr_purchase_requisitions';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id',
        'requisition_number', 'requesting_department_ref', 'priority', 'reason', 'estimated_value',
        'cost_centre_ref', 'budget_ref',
        'emergency', 'emergency_reason',
        'status', 'reviewed_by_user_id', 'reviewed_at', 'rejection_reason',
        'created_by_user_id', 'occurred_at', 'recorded_at',
        'version',
    ];

    protected static function newFactory(): PurchaseRequisitionFactory
    {
        return PurchaseRequisitionFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estimated_value' => 'decimal:2',
            'emergency' => 'boolean',
            'reviewed_at' => 'immutable_datetime',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public static function create(
        string $id,
        string $tenantId,
        string $facilityId,
        string $requisitionNumber,
        string $priority,
        string $createdByUserId,
        ?string $requestingDepartmentRef,
        ?string $reason,
        ?string $estimatedValue,
        bool $emergency,
        ?string $emergencyReason,
        ?string $costCentreRef = null,
        ?string $budgetRef = null,
        ?CarbonImmutable $occurredAt = null,
    ): self {
        $now = CarbonImmutable::now();

        return new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'requisition_number' => $requisitionNumber,
            'requesting_department_ref' => $requestingDepartmentRef,
            'priority' => $priority,
            'reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : null,
            'estimated_value' => $estimatedValue,
            'cost_centre_ref' => $costCentreRef !== null && trim($costCentreRef) !== '' ? trim($costCentreRef) : null,
            'budget_ref' => $budgetRef !== null && trim($budgetRef) !== '' ? trim($budgetRef) : null,
            'emergency' => $emergency,
            'emergency_reason' => $emergency && $emergencyReason !== null && trim($emergencyReason) !== '' ? trim($emergencyReason) : null,
            'status' => self::STATUS_DRAFT,
            'created_by_user_id' => $createdByUserId,
            'occurred_at' => $occurredAt ?? $now,
            'recorded_at' => $now,
        ]);
    }

    public function submit(): void
    {
        $this->status = self::STATUS_SUBMITTED;
    }

    public function reviseDraft(
        ?string $requestingDepartmentRef,
        string $priority,
        ?string $reason,
        ?string $costCentreRef = null,
        ?string $budgetRef = null,
    ): void {
        $this->requesting_department_ref = $requestingDepartmentRef;
        $this->priority = strtoupper(trim($priority));
        $this->reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;
        $this->cost_centre_ref = $costCentreRef !== null && trim($costCentreRef) !== '' ? trim($costCentreRef) : null;
        $this->budget_ref = $budgetRef !== null && trim($budgetRef) !== '' ? trim($budgetRef) : null;
    }

    public function approve(string $approvedByUserId): void
    {
        $this->status = self::STATUS_APPROVED;
        $this->reviewed_by_user_id = $approvedByUserId;
        $this->reviewed_at = CarbonImmutable::now();

        $this->recordThat(new PurchaseRequisitionApproved(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            requisitionId: (string) $this->id,
            requisitionNumber: (string) $this->requisition_number,
            priority: (string) $this->priority,
            emergency: (bool) $this->emergency,
            approvedByUserId: $approvedByUserId,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    public function reject(string $rejectedByUserId, string $reason): void
    {
        $this->status = self::STATUS_REJECTED;
        $this->reviewed_by_user_id = $rejectedByUserId;
        $this->reviewed_at = CarbonImmutable::now();
        $this->rejection_reason = trim($reason) !== '' ? trim($reason) : null;
    }

    public function cancel(): void
    {
        $this->status = self::STATUS_CANCELLED;
    }
}
