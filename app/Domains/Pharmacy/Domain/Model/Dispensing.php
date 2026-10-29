<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Domain\Model;

use App\Domains\Pharmacy\Contracts\Event\MedicationDispensed;
use App\Domains\Pharmacy\Database\Factories\DispensingFactory;
use App\Domains\Pharmacy\Domain\ValueObject\DispensingStatus;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * The Dispensing record aggregate — the pharmacy's actual release of medication
 * against a Pharmacy Order (16-pharmacy-domain.md §24, §44–§47, §127, §255;
 * ADR-184 D-B).
 *
 * WRITE-ONCE: created COMPLETED in one atomic step ({@see self::record()}) — the
 * `Dispense` handler reserves + consumes exactly this event's quantity and then
 * writes this row. `rx_dispensings` is append-only; a reversal (§128) is a future
 * compensating record. N Dispensing rows per Pharmacy Order (§23).
 *
 * `authorized_quantity_value` / `_unit` are the FROZEN §112 ceiling snapshot
 * (ADR-183 C.10), captured on the FIRST dispensing of the order and copied here
 * verbatim (ADR-184 D-D); NULL ⇒ `dispensed_without_ceiling` and no §112
 * enforcement for the order (ADR-183 D-M). `status` is an open-coded string
 * constant (the `StockAdjustment` precedent — NOT a PHP enum, NOT #[TypeScript]).
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
 * @property string $pharmacy_order_id
 * @property string $prescription_id
 * @property string $patient_id
 * @property string $dispensing_number
 * @property string $item_id
 * @property string $location_id
 * @property string|null $batch_number
 * @property string $quantity
 * @property string|null $quantity_unit
 * @property string|null $authorized_quantity_value
 * @property string|null $authorized_quantity_unit
 * @property bool $dispensed_without_ceiling
 * @property string $reservation_id
 * @property string $movement_id
 * @property string|null $financial_clearance_ref
 * @property string $dispensed_by_user_id
 * @property string $status
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $recorded_at
 * @property int $version
 */
final class Dispensing extends AggregateRoot
{
    /** @use HasFactory<DispensingFactory> */
    use HasFactory;

    public const STATUS_COMPLETED = DispensingStatus::COMPLETED;

    protected $table = 'rx_dispensings';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id',
        'pharmacy_order_id', 'prescription_id', 'patient_id',
        'dispensing_number',
        'item_id', 'location_id', 'batch_number', 'quantity', 'quantity_unit',
        'authorized_quantity_value', 'authorized_quantity_unit', 'dispensed_without_ceiling',
        'reservation_id', 'movement_id', 'financial_clearance_ref',
        'dispensed_by_user_id', 'status',
        'occurred_at', 'recorded_at', 'version',
    ];

    protected static function newFactory(): DispensingFactory
    {
        return DispensingFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'authorized_quantity_value' => 'decimal:4',
            'dispensed_without_ceiling' => 'boolean',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    /**
     * @param  array{
     *     id: string, tenantId: string, facilityId: string, pharmacyOrderId: string,
     *     prescriptionId: string, patientId: string, dispensingNumber: string,
     *     itemId: string, locationId: string, batchNumber: ?string, quantity: string,
     *     quantityUnit: ?string, authorizedQuantityValue: ?string, authorizedQuantityUnit: ?string,
     *     reservationId: string, movementId: string, financialClearanceRef: ?string,
     *     dispensedByUserId: string, occurredAt: CarbonImmutable, recordedAt: CarbonImmutable
     * }  $data
     */
    public static function record(array $data): self
    {
        $dispensing = new self([
            'id' => $data['id'],
            'tenant_id' => $data['tenantId'],
            'facility_id' => $data['facilityId'],
            'pharmacy_order_id' => $data['pharmacyOrderId'],
            'prescription_id' => $data['prescriptionId'],
            'patient_id' => $data['patientId'],
            'dispensing_number' => $data['dispensingNumber'],
            'item_id' => $data['itemId'],
            'location_id' => $data['locationId'],
            'batch_number' => self::clean($data['batchNumber']),
            'quantity' => $data['quantity'],
            'quantity_unit' => self::clean($data['quantityUnit']),
            'authorized_quantity_value' => $data['authorizedQuantityValue'],
            'authorized_quantity_unit' => self::clean($data['authorizedQuantityUnit']),
            'dispensed_without_ceiling' => $data['authorizedQuantityValue'] === null,
            'reservation_id' => $data['reservationId'],
            'movement_id' => $data['movementId'],
            'financial_clearance_ref' => self::clean($data['financialClearanceRef']),
            'dispensed_by_user_id' => $data['dispensedByUserId'],
            'status' => self::STATUS_COMPLETED,
            'occurred_at' => $data['occurredAt'],
            'recorded_at' => $data['recordedAt'],
        ]);

        $dispensing->recordThat(new MedicationDispensed(
            eventId: (string) Str::uuid7(),
            tenantId: $data['tenantId'],
            facilityId: $data['facilityId'],
            patientId: $data['patientId'],
            dispensingId: $data['id'],
            pharmacyOrderId: $data['pharmacyOrderId'],
            prescriptionId: $data['prescriptionId'],
            itemId: $data['itemId'],
            quantity: $data['quantity'],
            batchNumber: self::clean($data['batchNumber']),
            dispensedByUserId: $data['dispensedByUserId'],
            occurredAt: CarbonImmutable::now()->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));

        return $dispensing;
    }

    private static function clean(?string $value): ?string
    {
        return $value !== null && trim($value) !== '' ? trim($value) : null;
    }
}
