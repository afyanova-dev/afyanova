<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Model;

use App\Domains\Insurance\Contracts\Event\ClaimAdjudicated;
use App\Domains\Insurance\Domain\Exception\AdjudicationArithmeticMismatch;
use App\Domains\Insurance\Domain\Money;
use App\Domains\Insurance\Domain\ValueObject\AdjudicationLineStatus;
use App\Domains\Insurance\Domain\ValueObject\AdjudicationStatus;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Payer adjudication determination on an insurance claim
 * (21-insurance-and-payer-domain.md §84–§87; ADR-205).
 *
 * Aggregate root for claim adjudication:
 *   - Minted `ADJ-%08d` at creation (ADR-115, ADR-205).
 *   - Anchored immutably to a SUBMITTED claim.
 *   - Explicitly separates approved, patient responsibility, contractual adjustment,
 *     and denied amounts (Invariants 16, 17, 18, 19).
 *   - Preserves external payer response reference (Invariant 11).
 *   - Emits `ClaimAdjudicated` domain event (ADR-198 D-C; ADR-205).
 *
 * @domain      Insurance
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
 * @property string $adjudication_number
 * @property string $claim_id
 * @property string $payer_id
 * @property string $status
 * @property string $total_claimed_amount
 * @property string $total_approved_amount
 * @property string $total_denied_amount
 * @property string $total_patient_responsibility
 * @property string $total_contractual_adjustment
 * @property string $currency
 * @property string $adjudication_date
 * @property string|null $external_reference
 * @property string|null $notes
 * @property string|null $adjudicated_by_user_id
 * @property string $created_by_user_id
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $recorded_at
 * @property int $version
 */
final class Adjudication extends AggregateRoot
{
    protected $table = 'ins_adjudications';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'tenant_id',
        'facility_id',
        'adjudication_number',
        'claim_id',
        'payer_id',
        'status',
        'total_claimed_amount',
        'total_approved_amount',
        'total_denied_amount',
        'total_patient_responsibility',
        'total_contractual_adjustment',
        'currency',
        'adjudication_date',
        'external_reference',
        'notes',
        'adjudicated_by_user_id',
        'created_by_user_id',
        'occurred_at',
        'recorded_at',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'total_claimed_amount' => 'string',
            'total_approved_amount' => 'string',
            'total_denied_amount' => 'string',
            'total_patient_responsibility' => 'string',
            'total_contractual_adjustment' => 'string',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    /**
     * @return HasMany<AdjudicationLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(AdjudicationLine::class, 'adjudication_id')->orderBy('line_number');
    }

    /**
     * Factory: Record a formal payer adjudication on a claim (ADR-205).
     *
     * @param  array{
     *     id: string,
     *     tenantId: string,
     *     facilityId: string,
     *     adjudicationNumber: string,
     *     claimId: string,
     *     invoiceId: string,
     *     patientId: string,
     *     payerId: string,
     *     status: string,
     *     adjudicationDate: string,
     *     externalReference: ?string,
     *     notes: ?string,
     *     adjudicatedByUserId: ?string,
     *     createdByUserId: string,
     *     lines: list<array{
     *         claimLineId: string,
     *         status: string,
     *         claimedAmount: string,
     *         approvedAmount: string,
     *         deniedAmount: string,
     *         patientResponsibility: string,
     *         contractualAdjustment: string,
     *         denialReasonCode: ?string,
     *         remarks: ?string,
     *     }>,
     *     occurredAt: CarbonImmutable,
     *     recordedAt: CarbonImmutable,
     * } $data
     */
    public static function record(array $data): self
    {
        $status = AdjudicationStatus::assert($data['status']);

        $totalClaimed = '0.00';
        $totalApproved = '0.00';
        $totalDenied = '0.00';
        $totalPatientResp = '0.00';
        $totalContractualAdj = '0.00';

        $lineNumber = 1;
        /** @var list<AdjudicationLine> $lineModels */
        $lineModels = [];

        foreach ($data['lines'] as $lineData) {
            $lineStatus = AdjudicationLineStatus::assert($lineData['status']);
            $claimed = Money::of($lineData['claimedAmount']);
            $approved = Money::of($lineData['approvedAmount']);
            $denied = Money::of($lineData['deniedAmount']);
            $patientResp = Money::of($lineData['patientResponsibility']);
            $contractualAdj = Money::of($lineData['contractualAdjustment']);

            // Line arithmetic validation: claimed = approved + patient + contractual + denied
            $sum = Money::add(
                Money::add($approved, $patientResp),
                Money::add($contractualAdj, $denied),
            );

            if (bccomp($sum, $claimed, 2) !== 0) {
                throw AdjudicationArithmeticMismatch::forLine(
                    $lineNumber,
                    $claimed,
                    $approved,
                    $patientResp,
                    $contractualAdj,
                    $denied,
                );
            }

            $totalClaimed = Money::add($totalClaimed, $claimed);
            $totalApproved = Money::add($totalApproved, $approved);
            $totalDenied = Money::add($totalDenied, $denied);
            $totalPatientResp = Money::add($totalPatientResp, $patientResp);
            $totalContractualAdj = Money::add($totalContractualAdj, $contractualAdj);

            $lineModels[] = new AdjudicationLine([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $data['tenantId'],
                'adjudication_id' => $data['id'],
                'claim_line_id' => $lineData['claimLineId'],
                'line_number' => $lineNumber++,
                'status' => $lineStatus,
                'claimed_amount' => $claimed,
                'approved_amount' => $approved,
                'denied_amount' => $denied,
                'patient_responsibility' => $patientResp,
                'contractual_adjustment' => $contractualAdj,
                'denial_reason_code' => $lineData['denialReasonCode'] !== null && trim($lineData['denialReasonCode']) !== ''
                    ? trim($lineData['denialReasonCode'])
                    : null,
                'remarks' => $lineData['remarks'] !== null && trim($lineData['remarks']) !== ''
                    ? trim($lineData['remarks'])
                    : null,
                'occurred_at' => $data['occurredAt'],
                'recorded_at' => $data['recordedAt'],
                'version' => 0,
            ]);
        }

        $adjudication = new self([
            'id' => $data['id'],
            'tenant_id' => $data['tenantId'],
            'facility_id' => $data['facilityId'],
            'adjudication_number' => $data['adjudicationNumber'],
            'claim_id' => $data['claimId'],
            'payer_id' => $data['payerId'],
            'status' => $status,
            'total_claimed_amount' => $totalClaimed,
            'total_approved_amount' => $totalApproved,
            'total_denied_amount' => $totalDenied,
            'total_patient_responsibility' => $totalPatientResp,
            'total_contractual_adjustment' => $totalContractualAdj,
            'currency' => 'TZS',
            'adjudication_date' => $data['adjudicationDate'],
            'external_reference' => $data['externalReference'] !== null && trim($data['externalReference']) !== ''
                ? trim($data['externalReference'])
                : null,
            'notes' => $data['notes'] !== null && trim($data['notes']) !== ''
                ? trim($data['notes'])
                : null,
            'adjudicated_by_user_id' => $data['adjudicatedByUserId'],
            'created_by_user_id' => $data['createdByUserId'],
            'occurred_at' => $data['occurredAt'],
            'recorded_at' => $data['recordedAt'],
            'version' => 0,
        ]);

        $adjudication->setRelation('lines', collect($lineModels));

        $adjudication->recordThat(new ClaimAdjudicated(
            eventId: (string) Str::uuid7(),
            tenantId: $data['tenantId'],
            facilityId: $data['facilityId'],
            adjudicationId: $data['id'],
            claimId: $data['claimId'],
            invoiceId: $data['invoiceId'],
            patientId: $data['patientId'],
            payerId: $data['payerId'],
            status: $status,
            totalClaimedAmount: $totalClaimed,
            totalApprovedAmount: $totalApproved,
            totalDeniedAmount: $totalDenied,
            totalPatientResponsibility: $totalPatientResp,
            totalContractualAdjustment: $totalContractualAdj,
            currency: 'TZS',
            occurredAt: $data['occurredAt']->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));

        return $adjudication;
    }

    public function isApproved(): bool
    {
        return $this->status === AdjudicationStatus::APPROVED;
    }

    public function isPartiallyApproved(): bool
    {
        return $this->status === AdjudicationStatus::PARTIALLY_APPROVED;
    }

    public function isDenied(): bool
    {
        return $this->status === AdjudicationStatus::DENIED;
    }

    public function isPended(): bool
    {
        return $this->status === AdjudicationStatus::PENDED;
    }

    public function isRejected(): bool
    {
        return $this->status === AdjudicationStatus::REJECTED;
    }
}
