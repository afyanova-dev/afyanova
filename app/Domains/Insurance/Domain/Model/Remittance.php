<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Model;

use App\Domains\Insurance\Contracts\Event\RemittanceLineSettled;
use App\Domains\Insurance\Domain\Exception\RemittanceAlreadyReconciled;
use App\Domains\Insurance\Domain\Exception\RemittanceLineNotFound;
use App\Domains\Insurance\Domain\Money;
use App\Domains\Insurance\Domain\ValueObject\RemittanceLineStatus;
use App\Domains\Insurance\Domain\ValueObject\RemittanceStatus;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Payer remittance advice batch (ERA, EFT trace, check batch)
 * (21-insurance-and-payer-domain.md §98–§104; ADR-205).
 *
 * Aggregate root for payer remittance advice:
 *   - Minted `REM-%08d` at creation (ADR-115, ADR-205).
 *   - Tracks total payer settlement amount and reconciled vs unreconciled amounts.
 *   - Emits `RemittanceLineSettled` when individual claim lines are reconciled.
 *   - Status: RECORDED | RECONCILED | DISCREPANT.
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
 * @property string $remittance_number
 * @property string $payer_id
 * @property string $payment_reference
 * @property string $payment_method
 * @property string $remittance_date
 * @property string $total_amount
 * @property string $reconciled_amount
 * @property string $unreconciled_amount
 * @property string $status
 * @property string $currency
 * @property string|null $notes
 * @property string $created_by_user_id
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $recorded_at
 * @property int $version
 */
final class Remittance extends AggregateRoot
{
    protected $table = 'ins_remittances';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'tenant_id',
        'facility_id',
        'remittance_number',
        'payer_id',
        'payment_reference',
        'payment_method',
        'remittance_date',
        'total_amount',
        'reconciled_amount',
        'unreconciled_amount',
        'status',
        'currency',
        'notes',
        'created_by_user_id',
        'occurred_at',
        'recorded_at',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'string',
            'reconciled_amount' => 'string',
            'unreconciled_amount' => 'string',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    /**
     * @return HasMany<RemittanceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(RemittanceLine::class, 'remittance_id')->orderBy('line_number');
    }

    /**
     * Factory: Record an incoming remittance advice batch (ADR-205).
     *
     * @param  array{
     *     id: string,
     *     tenantId: string,
     *     facilityId: string,
     *     remittanceNumber: string,
     *     payerId: string,
     *     paymentReference: string,
     *     paymentMethod: string,
     *     remittanceDate: string,
     *     totalAmount: string,
     *     notes: ?string,
     *     createdByUserId: string,
     *     occurredAt: CarbonImmutable,
     *     recordedAt: CarbonImmutable,
     *     lines?: list<array{
     *         claimId: ?string,
     *         adjudicationId: ?string,
     *         externalClaimReference: ?string,
     *         claimedAmount: string,
     *         paidAmount: string,
     *         contractualAdjustment: string,
     *         patientResponsibility: string,
     *         deniedAmount: string,
     *         status?: ?string,
     *         discrepancyReason?: ?string,
     *     }>,
     * } $data
     */
    public static function record(array $data): self
    {
        $totalAmount = Money::of($data['totalAmount']);
        $reconciledAmount = '0.00';

        $lineNumber = 1;
        /** @var list<RemittanceLine> $lineModels */
        $lineModels = [];

        if (isset($data['lines']) && count($data['lines']) > 0) {
            foreach ($data['lines'] as $lineData) {
                $paid = Money::of($lineData['paidAmount']);
                $claimed = Money::of($lineData['claimedAmount']);
                $contractual = Money::of($lineData['contractualAdjustment']);
                $patient = Money::of($lineData['patientResponsibility']);
                $denied = Money::of($lineData['deniedAmount']);
                $status = isset($lineData['status'])
                    ? RemittanceLineStatus::assert($lineData['status'])
                    : RemittanceLineStatus::UNMATCHED;

                if ($status === RemittanceLineStatus::MATCHED) {
                    $reconciledAmount = Money::add($reconciledAmount, $paid);
                }

                $lineModels[] = new RemittanceLine([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $data['tenantId'],
                    'remittance_id' => $data['id'],
                    'line_number' => $lineNumber++,
                    'claim_id' => $lineData['claimId'] ?? null,
                    'adjudication_id' => $lineData['adjudicationId'] ?? null,
                    'external_claim_reference' => $lineData['externalClaimReference'] ?? null,
                    'claimed_amount' => $claimed,
                    'paid_amount' => $paid,
                    'contractual_adjustment' => $contractual,
                    'patient_responsibility' => $patient,
                    'denied_amount' => $denied,
                    'status' => $status,
                    'discrepancy_reason' => $lineData['discrepancyReason'] ?? null,
                    'occurred_at' => $data['occurredAt'],
                    'recorded_at' => $data['recordedAt'],
                    'version' => 0,
                ]);
            }
        }

        $unreconciledAmount = Money::subtract($totalAmount, $reconciledAmount);
        $status = bccomp($unreconciledAmount, '0', 2) === 0 && count($lineModels) > 0
            ? RemittanceStatus::RECONCILED
            : RemittanceStatus::RECORDED;

        $remittance = new self([
            'id' => $data['id'],
            'tenant_id' => $data['tenantId'],
            'facility_id' => $data['facilityId'],
            'remittance_number' => $data['remittanceNumber'],
            'payer_id' => $data['payerId'],
            'payment_reference' => trim($data['paymentReference']),
            'payment_method' => trim($data['paymentMethod']),
            'remittance_date' => $data['remittanceDate'],
            'total_amount' => $totalAmount,
            'reconciled_amount' => $reconciledAmount,
            'unreconciled_amount' => $unreconciledAmount,
            'status' => $status,
            'currency' => 'TZS',
            'notes' => $data['notes'] !== null && trim($data['notes']) !== '' ? trim($data['notes']) : null,
            'created_by_user_id' => $data['createdByUserId'],
            'occurred_at' => $data['occurredAt'],
            'recorded_at' => $data['recordedAt'],
            'version' => 0,
        ]);

        $remittance->setRelation('lines', collect($lineModels));

        return $remittance;
    }

    /**
     * Reconcile an individual remittance line against an adjudicated claim (ADR-205).
     *
     * @param  array{
     *     remittanceLineId: string,
     *     claimId: string,
     *     invoiceId: string,
     *     patientId: string,
     *     payerId: string,
     *     paidAmount: string,
     *     contractualAdjustment: string,
     *     patientResponsibility: string,
     *     deniedAmount: string,
     *     discrepancyReason: ?string,
     *     settledByUserId: string,
     * } $reconciliation
     */
    public function reconcileLine(array $reconciliation, CarbonImmutable $now): RemittanceLine
    {
        if ($this->status === RemittanceStatus::RECONCILED) {
            throw RemittanceAlreadyReconciled::forRemittance($this->id);
        }

        /** @var RemittanceLine|null $line */
        $line = $this->lines->firstWhere('id', $reconciliation['remittanceLineId']);
        if ($line === null) {
            throw RemittanceLineNotFound::id($this->id, $reconciliation['remittanceLineId']);
        }

        $paid = Money::of($reconciliation['paidAmount']);
        $contractual = Money::of($reconciliation['contractualAdjustment']);
        $patient = Money::of($reconciliation['patientResponsibility']);
        $denied = Money::of($reconciliation['deniedAmount']);

        $lineStatus = $reconciliation['discrepancyReason'] !== null
            ? RemittanceLineStatus::DISCREPANT
            : RemittanceLineStatus::MATCHED;

        $line->claim_id = $reconciliation['claimId'];
        $line->paid_amount = $paid;
        $line->contractual_adjustment = $contractual;
        $line->patient_responsibility = $patient;
        $line->denied_amount = $denied;
        $line->status = $lineStatus;
        $line->discrepancy_reason = $reconciliation['discrepancyReason'];
        $line->version++;

        // Update aggregate amounts
        $this->reconciled_amount = Money::add($this->reconciled_amount, $paid);
        $this->unreconciled_amount = Money::subtract($this->total_amount, $this->reconciled_amount);

        // Check if all lines are now settled
        $hasUnmatched = $this->lines->contains(fn (RemittanceLine $l) => $l->status === RemittanceLineStatus::UNMATCHED);
        $hasDiscrepancy = $this->lines->contains(fn (RemittanceLine $l) => $l->status === RemittanceLineStatus::DISCREPANT);

        if (! $hasUnmatched) {
            $this->status = $hasDiscrepancy ? RemittanceStatus::DISCREPANT : RemittanceStatus::RECONCILED;
        }

        $this->version++;

        // Emit cross-domain event to trigger Billing reconciliation
        $this->recordThat(new RemittanceLineSettled(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            remittanceId: (string) $this->id,
            remittanceLineId: (string) $line->id,
            claimId: $reconciliation['claimId'],
            invoiceId: $reconciliation['invoiceId'],
            patientId: $reconciliation['patientId'],
            payerId: $reconciliation['payerId'],
            paidAmount: $paid,
            contractualAdjustment: $contractual,
            patientResponsibility: $patient,
            deniedAmount: $denied,
            paymentReference: (string) $this->payment_reference,
            settledByUserId: $reconciliation['settledByUserId'],
            occurredAt: $now->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));

        return $line;
    }

    public function isReconciled(): bool
    {
        return $this->status === RemittanceStatus::RECONCILED;
    }

    public function isDiscrepant(): bool
    {
        return $this->status === RemittanceStatus::DISCREPANT;
    }
}
