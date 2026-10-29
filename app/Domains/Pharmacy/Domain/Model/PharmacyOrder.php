<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Domain\Model;

use App\Domains\Pharmacy\Contracts\Event\PharmacyOrderCancelled;
use App\Domains\Pharmacy\Contracts\Event\PharmacyOrderRejected;
use App\Domains\Pharmacy\Contracts\Event\PharmacyOrderValidated;
use App\Domains\Pharmacy\Database\Factories\PharmacyOrderFactory;
use App\Domains\Pharmacy\Domain\ValueObject\PharmacyOrderStatus;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * The Pharmacy Order aggregate — the operational fulfilment task for a
 * Prescription (16-pharmacy-domain.md §21, §22, §106, §122, §254; ADR-182 D-C).
 *
 * Distinct from the Pharm.1 Prescription RECORD (§21). Pharm.2a delivers the
 * pharmacist validation workflow only: {@see self::open()} (from an ACTIVE
 * prescription) → RECEIVED → {@see self::validate()} → VALIDATED;
 * {@see self::reject()} / {@see self::hold()} / {@see self::releaseHold()} /
 * {@see self::cancel()}. `READY` / dispensing states + the reservation /
 * consumption cross-domain work are Pharm.3. `status` is an open-coded string
 * constant (the `StockAdjustment` precedent — NOT a PHP enum, NOT `#[TypeScript]`).
 *
 * @domain      Pharmacy
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
 * @property string $prescription_id
 * @property string $patient_id
 * @property string $order_number
 * @property string|null $dispensing_location_id
 * @property string $priority
 * @property string $status
 * @property string|null $validated_by_user_id
 * @property string|null $reject_reason_code
 * @property string|null $hold_reason_code
 * @property string|null $cancel_reason_code
 * @property string|null $reason_note
 * @property string|null $cancelled_by_user_id
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $recorded_at
 * @property int $version
 */
final class PharmacyOrder extends AggregateRoot
{
    /** @use HasFactory<PharmacyOrderFactory> */
    use HasFactory;

    public const STATUS_RECEIVED = PharmacyOrderStatus::RECEIVED;

    public const STATUS_VALIDATED = PharmacyOrderStatus::VALIDATED;

    public const STATUS_REJECTED = PharmacyOrderStatus::REJECTED;

    public const STATUS_ON_HOLD = PharmacyOrderStatus::ON_HOLD;

    public const STATUS_CANCELLED = PharmacyOrderStatus::CANCELLED;

    public const STATUS_PARTIALLY_DISPENSED = PharmacyOrderStatus::PARTIALLY_DISPENSED;

    public const STATUS_DISPENSED = PharmacyOrderStatus::DISPENSED;

    protected $table = 'rx_pharmacy_orders';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id',
        'prescription_id', 'patient_id', 'order_number',
        'dispensing_location_id', 'priority',
        'status', 'validated_by_user_id',
        'reject_reason_code', 'hold_reason_code', 'cancel_reason_code', 'reason_note',
        'cancelled_by_user_id',
        'occurred_at', 'recorded_at',
        'version',
    ];

    protected static function newFactory(): PharmacyOrderFactory
    {
        return PharmacyOrderFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public static function open(
        string $id,
        string $tenantId,
        string $facilityId,
        string $prescriptionId,
        string $patientId,
        string $orderNumber,
        ?string $dispensingLocationId,
        string $priority,
        CarbonImmutable $occurredAt,
        CarbonImmutable $recordedAt,
    ): self {
        return new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'prescription_id' => $prescriptionId,
            'patient_id' => $patientId,
            'order_number' => $orderNumber,
            'dispensing_location_id' => $dispensingLocationId,
            'priority' => $priority,
            'status' => self::STATUS_RECEIVED,
            'occurred_at' => $occurredAt,
            'recorded_at' => $recordedAt,
        ]);
    }

    /** RECEIVED → VALIDATED — a pharmacist attested the prescription (§106). */
    public function validate(string $validatedByUserId): void
    {
        $this->status = self::STATUS_VALIDATED;
        $this->validated_by_user_id = $validatedByUserId;

        $this->recordThat(new PharmacyOrderValidated(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            patientId: (string) $this->patient_id,
            pharmacyOrderId: (string) $this->id,
            prescriptionId: (string) $this->prescription_id,
            validatedByUserId: $validatedByUserId,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    /** RECEIVED | VALIDATED → REJECTED (terminal). Coded reason; note ROW-ONLY (§124). */
    public function reject(string $rejectedByUserId, string $reasonCode, ?string $note): void
    {
        $this->status = self::STATUS_REJECTED;
        $this->reject_reason_code = $reasonCode;
        $this->reason_note = self::clean($note);

        $this->recordThat(new PharmacyOrderRejected(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            patientId: (string) $this->patient_id,
            pharmacyOrderId: (string) $this->id,
            prescriptionId: (string) $this->prescription_id,
            rejectedByUserId: $rejectedByUserId,
            reasonCode: $reasonCode,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    /** RECEIVED | VALIDATED → ON_HOLD. Coded reason; note ROW-ONLY (§123). NO event. */
    public function hold(string $reasonCode, ?string $note): void
    {
        $this->status = self::STATUS_ON_HOLD;
        $this->hold_reason_code = $reasonCode;
        $this->reason_note = self::clean($note);
    }

    /** ON_HOLD → RECEIVED — must re-validate (conservative). NO event. */
    public function releaseHold(): void
    {
        $this->status = self::STATUS_RECEIVED;
        $this->hold_reason_code = null;
        $this->reason_note = null;
    }

    /**
     * VALIDATED | PARTIALLY_DISPENSED → PARTIALLY_DISPENSED | DISPENSED — the
     * fulfilment rollup advanced by a `Dispensing` event (ADR-184 D-C). NO event
     * on the order (the `Dispensing` aggregate records `MedicationDispensed`).
     *
     * `$cumulativeDispensed` / `$authorizedQuantity` are fixed-scale decimal
     * strings; a null `$authorizedQuantity` (no C.10 ceiling — ADR-183 D-M)
     * never reaches DISPENSED here (a future "close order" command does).
     *
     * @param  numeric-string  $cumulativeDispensed
     * @param  numeric-string|null  $authorizedQuantity
     */
    public function applyDispensing(string $cumulativeDispensed, ?string $authorizedQuantity): void
    {
        $this->status = ($authorizedQuantity !== null && bccomp($cumulativeDispensed, $authorizedQuantity, 4) >= 0)
            ? self::STATUS_DISPENSED
            : self::STATUS_PARTIALLY_DISPENSED;
    }

    /**
     * PARTIALLY_DISPENSED | DISPENSED → PARTIALLY_DISPENSED | VALIDATED — the
     * rollup RE-computed after a `DispensingReversal` (ADR-185 D-E). §112 headroom
     * is freed. `$cumulativeDispensed` is now net of reversals; == 0 means the
     * order is dispensable afresh (the next first-dispense re-snapshots the C.10
     * ceiling). NO event on the order.
     *
     * @param  numeric-string  $cumulativeDispensed
     * @param  numeric-string|null  $authorizedQuantity
     */
    public function applyReversal(string $cumulativeDispensed, ?string $authorizedQuantity): void
    {
        if (bccomp($cumulativeDispensed, '0', 4) <= 0) {
            $this->status = self::STATUS_VALIDATED;

            return;
        }

        $this->status = ($authorizedQuantity !== null && bccomp($cumulativeDispensed, $authorizedQuantity, 4) >= 0)
            ? self::STATUS_DISPENSED
            : self::STATUS_PARTIALLY_DISPENSED;
    }

    /** Any non-terminal → CANCELLED (terminal). Coded reason; note ROW-ONLY (§58). */
    public function cancel(string $cancelledByUserId, string $reasonCode, ?string $note): void
    {
        $this->status = self::STATUS_CANCELLED;
        $this->cancelled_by_user_id = $cancelledByUserId;
        $this->cancel_reason_code = $reasonCode;
        $this->reason_note = self::clean($note);

        $this->recordThat(new PharmacyOrderCancelled(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            patientId: (string) $this->patient_id,
            pharmacyOrderId: (string) $this->id,
            prescriptionId: (string) $this->prescription_id,
            cancelledByUserId: $cancelledByUserId,
            reasonCode: $reasonCode,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    private static function clean(?string $value): ?string
    {
        return $value !== null && trim($value) !== '' ? trim($value) : null;
    }
}
