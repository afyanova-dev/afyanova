<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Model;

use App\Domains\Procurement\Database\Factories\QuotationEvaluationFactory;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * One evaluator's structured evaluation of a supplier quotation
 * (19-procurement-domain.md §36, §205–§207; ADR-174 D-B / D-C).
 *
 * ONE per (RFQ quotation, evaluator). Lifecycle: DRAFT (revisable) → SUBMITTED
 * (locked; the §207 independence filter reveals peers once you submit). Records
 * NO event (ADR-173 D-I continues); the `mark*` methods are plain setters and
 * `save()` bumps `version` on the dirtied row (REAL optimistic concurrency —
 * this is a mutable working record, not an append-only ledger). The four §36
 * dimension scores live on the row; `overall_score` is the weighted mean,
 * snapshotted by `QuotationEvaluationScorer` at `submit()`.
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
 * @property string $rfq_id
 * @property string $quotation_id
 * @property string $evaluator_user_id
 * @property int|null $commercial_score
 * @property int|null $technical_score
 * @property int|null $quality_score
 * @property int|null $delivery_score
 * @property int|null $overall_score
 * @property string|null $comments
 * @property string $status
 * @property CarbonImmutable|null $submitted_at
 * @property string $created_by_user_id
 * @property CarbonImmutable|null $occurred_at
 * @property CarbonImmutable|null $recorded_at
 * @property int $version
 */
final class QuotationEvaluation extends AggregateRoot
{
    /** @use HasFactory<QuotationEvaluationFactory> */
    use HasFactory;

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_SUBMITTED = 'SUBMITTED';

    protected $table = 'pcr_quotation_evaluations';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id',
        'rfq_id', 'quotation_id', 'evaluator_user_id',
        'commercial_score', 'technical_score', 'quality_score', 'delivery_score', 'overall_score',
        'comments', 'status', 'submitted_at',
        'created_by_user_id', 'occurred_at', 'recorded_at',
        'version',
    ];

    protected static function newFactory(): QuotationEvaluationFactory
    {
        return QuotationEvaluationFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'commercial_score' => 'integer',
            'technical_score' => 'integer',
            'quality_score' => 'integer',
            'delivery_score' => 'integer',
            'overall_score' => 'integer',
            'submitted_at' => 'immutable_datetime',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public static function create(
        string $id,
        string $tenantId,
        string $facilityId,
        string $rfqId,
        string $quotationId,
        string $evaluatorUserId,
        ?int $commercialScore,
        ?int $technicalScore,
        ?int $qualityScore,
        ?int $deliveryScore,
        ?string $comments,
        ?CarbonImmutable $occurredAt = null,
    ): self {
        $now = CarbonImmutable::now();

        return new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'rfq_id' => $rfqId,
            'quotation_id' => $quotationId,
            'evaluator_user_id' => $evaluatorUserId,
            'commercial_score' => $commercialScore,
            'technical_score' => $technicalScore,
            'quality_score' => $qualityScore,
            'delivery_score' => $deliveryScore,
            'overall_score' => null,
            'comments' => $comments !== null && trim($comments) !== '' ? trim($comments) : null,
            'status' => self::STATUS_DRAFT,
            'created_by_user_id' => $evaluatorUserId,
            'occurred_at' => $occurredAt ?? $now,
            'recorded_at' => $now,
        ]);
    }

    /**
     * Patch a DRAFT evaluation. A null score leaves that dimension unchanged;
     * `comments === null` leaves the note unchanged, `''` clears it.
     */
    public function revise(
        ?int $commercialScore,
        ?int $technicalScore,
        ?int $qualityScore,
        ?int $deliveryScore,
        ?string $comments,
    ): void {
        if ($commercialScore !== null) {
            $this->commercial_score = $commercialScore;
        }
        if ($technicalScore !== null) {
            $this->technical_score = $technicalScore;
        }
        if ($qualityScore !== null) {
            $this->quality_score = $qualityScore;
        }
        if ($deliveryScore !== null) {
            $this->delivery_score = $deliveryScore;
        }
        if ($comments !== null) {
            $this->comments = trim($comments) !== '' ? trim($comments) : null;
        }
    }

    public function submit(int $overallScore): void
    {
        $this->overall_score = $overallScore;
        $this->status = self::STATUS_SUBMITTED;
        $this->submitted_at = CarbonImmutable::now();
    }
}
