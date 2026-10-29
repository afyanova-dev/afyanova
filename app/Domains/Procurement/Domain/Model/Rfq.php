<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Model;

use App\Domains\Procurement\Database\Factories\RfqFactory;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * The Request for Quotation aggregate (19-procurement-domain.md §29–§31, §37,
 * §245; ADR-173 D-B / D-D).
 *
 * §31 lifecycle: DRAFT → ISSUED → CLOSED → AWARDED (+ CANCELLED from
 * DRAFT | ISSUED | CLOSED, NOT from AWARDED). Records NO event (ADR-173 D-I —
 * the six §308 sourcing events are deferred until a consumer lands); the
 * `mark*` methods are plain status setters, and `save()` bumps `version` on the
 * dirtied row. Lines live on `pcr_rfq_lines`, invited suppliers on
 * `pcr_rfq_invitations`, the award allocation on `pcr_rfq_awards` (append-only).
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
 * @property string $rfq_number
 * @property string|null $requisition_id
 * @property string $title
 * @property string|null $reason
 * @property CarbonImmutable|null $required_date
 * @property CarbonImmutable|null $closing_date
 * @property string|null $terms
 * @property bool|null $requires_evaluation
 * @property string $status
 * @property string|null $cancellation_reason
 * @property string|null $awarded_by_user_id
 * @property CarbonImmutable|null $awarded_at
 * @property string $created_by_user_id
 * @property CarbonImmutable|null $occurred_at
 * @property CarbonImmutable|null $recorded_at
 * @property int $version
 */
final class Rfq extends AggregateRoot
{
    /** @use HasFactory<RfqFactory> */
    use HasFactory;

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_ISSUED = 'ISSUED';

    public const STATUS_CLOSED = 'CLOSED';

    public const STATUS_AWARDED = 'AWARDED';

    public const STATUS_CANCELLED = 'CANCELLED';

    protected $table = 'pcr_rfqs';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id',
        'rfq_number', 'requisition_id', 'title', 'reason',
        'required_date', 'closing_date', 'terms', 'requires_evaluation',
        'status', 'cancellation_reason', 'awarded_by_user_id', 'awarded_at',
        'created_by_user_id', 'occurred_at', 'recorded_at',
        'version',
    ];

    protected static function newFactory(): RfqFactory
    {
        return RfqFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'required_date' => 'immutable_date',
            'closing_date' => 'immutable_date',
            'requires_evaluation' => 'boolean',
            'awarded_at' => 'immutable_datetime',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public static function create(
        string $id,
        string $tenantId,
        string $facilityId,
        string $rfqNumber,
        ?string $requisitionId,
        string $title,
        string $createdByUserId,
        ?string $reason,
        ?string $requiredDate,
        ?string $closingDate,
        ?string $terms,
        ?CarbonImmutable $occurredAt = null,
    ): self {
        $now = CarbonImmutable::now();

        return new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'rfq_number' => $rfqNumber,
            'requisition_id' => $requisitionId,
            'title' => trim($title),
            'reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : null,
            'required_date' => $requiredDate !== null && trim($requiredDate) !== '' ? CarbonImmutable::parse($requiredDate) : null,
            'closing_date' => $closingDate !== null && trim($closingDate) !== '' ? CarbonImmutable::parse($closingDate) : null,
            'terms' => $terms !== null && trim($terms) !== '' ? trim($terms) : null,
            'status' => self::STATUS_DRAFT,
            'created_by_user_id' => $createdByUserId,
            'occurred_at' => $occurredAt ?? $now,
            'recorded_at' => $now,
        ]);
    }

    /** ADR-175 D-C — flag this RFQ so its award is HARD-gated on a complete evaluation set. */
    public function requireEvaluation(): void
    {
        $this->requires_evaluation = true;
    }

    public function markIssued(): void
    {
        $this->status = self::STATUS_ISSUED;
    }

    public function markClosed(): void
    {
        $this->status = self::STATUS_CLOSED;
    }

    public function markAwarded(string $decidedByUserId): void
    {
        $this->status = self::STATUS_AWARDED;
        $this->awarded_by_user_id = $decidedByUserId;
        $this->awarded_at = CarbonImmutable::now();
    }

    public function markCancelled(string $reason): void
    {
        $this->status = self::STATUS_CANCELLED;
        $this->cancellation_reason = trim($reason) !== '' ? trim($reason) : null;
    }
}
