<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Domain\Model;

use App\Domains\Pharmacy\Contracts\Event\MedicationPickedUp;
use App\Domains\Pharmacy\Database\Factories\PickupFactory;
use App\Domains\Pharmacy\Domain\ValueObject\PickupStatus;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * The Medication Pickup aggregate — the outpatient collection of a dispensing
 * (16-pharmacy-domain.md §26, §29–§31, §47, §129; ADR-186 D-B).
 *
 * MUTABLE (NOT append-only). ONE per Dispensing, auto-created `READY_FOR_PICKUP`
 * in the `Dispense` transaction ({@see self::open()}). `RecordPickup` /
 * `MarkPickupNotCollected` / `CancelPickup` drive the lifecycle. `status` is an
 * open-coded string constant (the `PharmacyOrderStatus` precedent — NOT a PHP
 * enum, NOT #[TypeScript]). `recipient_name` / `verification_note` /
 * `outcome_note` are free text ROW-ONLY (ADR-136).
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
 * @property string $dispensing_id
 * @property string $pharmacy_order_id
 * @property string $prescription_id
 * @property string $patient_id
 * @property string $pickup_number
 * @property string $status
 * @property string|null $recipient_type
 * @property string|null $recipient_name
 * @property string|null $verification_note
 * @property string|null $collected_by_user_id
 * @property CarbonImmutable|null $collected_at
 * @property string|null $outcome_reason_code
 * @property string|null $outcome_note
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $recorded_at
 * @property int $version
 */
final class Pickup extends AggregateRoot
{
    /** @use HasFactory<PickupFactory> */
    use HasFactory;

    public const STATUS_READY_FOR_PICKUP = PickupStatus::READY_FOR_PICKUP;

    public const STATUS_PICKED_UP = PickupStatus::PICKED_UP;

    public const STATUS_NOT_COLLECTED = PickupStatus::NOT_COLLECTED;

    public const STATUS_CANCELLED = PickupStatus::CANCELLED;

    protected $table = 'rx_pickups';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id',
        'dispensing_id', 'pharmacy_order_id', 'prescription_id', 'patient_id',
        'pickup_number', 'status',
        'recipient_type', 'recipient_name', 'verification_note',
        'collected_by_user_id', 'collected_at',
        'outcome_reason_code', 'outcome_note',
        'occurred_at', 'recorded_at', 'version',
    ];

    protected static function newFactory(): PickupFactory
    {
        return PickupFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'collected_at' => 'immutable_datetime',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    /**
     * Auto-created `READY_FOR_PICKUP` alongside a `Dispensing` (ADR-186 D-C).
     * Records NO event — creation is not a decision.
     */
    public static function open(
        string $id,
        string $tenantId,
        string $facilityId,
        string $dispensingId,
        string $pharmacyOrderId,
        string $prescriptionId,
        string $patientId,
        string $pickupNumber,
        CarbonImmutable $occurredAt,
        CarbonImmutable $recordedAt,
    ): self {
        return new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'dispensing_id' => $dispensingId,
            'pharmacy_order_id' => $pharmacyOrderId,
            'prescription_id' => $prescriptionId,
            'patient_id' => $patientId,
            'pickup_number' => $pickupNumber,
            'status' => self::STATUS_READY_FOR_PICKUP,
            'occurred_at' => $occurredAt,
            'recorded_at' => $recordedAt,
        ]);
    }

    /**
     * {READY_FOR_PICKUP | NOT_COLLECTED} → PICKED_UP. Records `MedicationPickedUp`.
     * `$recipientName` / `$verificationNote` are ROW-ONLY.
     */
    public function recordCollection(
        string $collectedByUserId,
        string $recipientType,
        ?string $recipientName,
        ?string $verificationNote,
        CarbonImmutable $collectedAt,
    ): void {
        $this->status = self::STATUS_PICKED_UP;
        $this->recipient_type = $recipientType;
        $this->recipient_name = self::clean($recipientName);
        $this->verification_note = self::clean($verificationNote);
        $this->collected_by_user_id = $collectedByUserId;
        $this->collected_at = $collectedAt;

        $this->recordThat(new MedicationPickedUp(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            patientId: (string) $this->patient_id,
            pickupId: (string) $this->id,
            dispensingId: (string) $this->dispensing_id,
            pharmacyOrderId: (string) $this->pharmacy_order_id,
            prescriptionId: (string) $this->prescription_id,
            recipientType: $recipientType,
            collectedByUserId: $collectedByUserId,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    /** READY_FOR_PICKUP → NOT_COLLECTED. Coded reason; note ROW-ONLY. NO event. */
    public function markNotCollected(string $reasonCode, ?string $note): void
    {
        $this->status = self::STATUS_NOT_COLLECTED;
        $this->outcome_reason_code = $reasonCode;
        $this->outcome_note = self::clean($note);
    }

    /** {READY_FOR_PICKUP | NOT_COLLECTED} → CANCELLED. Coded reason; note ROW-ONLY. NO event. */
    public function cancel(string $reasonCode, ?string $note): void
    {
        $this->status = self::STATUS_CANCELLED;
        $this->outcome_reason_code = $reasonCode;
        $this->outcome_note = self::clean($note);
    }

    public function isPickedUp(): bool
    {
        return $this->status === self::STATUS_PICKED_UP;
    }

    private static function clean(?string $value): ?string
    {
        return $value !== null && trim($value) !== '' ? trim($value) : null;
    }
}
