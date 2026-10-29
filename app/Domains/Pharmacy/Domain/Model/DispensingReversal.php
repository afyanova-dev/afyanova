<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Domain\Model;

use App\Domains\Pharmacy\Contracts\Event\MedicationDispensingReversed;
use App\Domains\Pharmacy\Database\Factories\DispensingReversalFactory;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * The Dispensing Reversal record — the error-correction inverse of a
 * {@see Dispensing} (16-pharmacy-domain.md §59, §60, §128; ADR-185 D-B).
 *
 * WRITE-ONCE: created COMPLETED in one atomic step ({@see self::record()}) — the
 * `ReverseDispensing` handler returns the exact stock (`InventoryApi::reverseConsumption`)
 * and then writes this row. `rx_dispensing_reversals` is append-only; the
 * original `rx_dispensings` row is NEVER touched (§128). ONE reversal per
 * Dispensing. `status` is a single open-coded 'COMPLETED' constant (the
 * `Dispensing` / `StockAdjustment` precedent — NOT a PHP enum, NOT #[TypeScript]).
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
 * @property string $reversal_number
 * @property string $item_id
 * @property string $location_id
 * @property string|null $batch_number
 * @property string $quantity
 * @property string $reversal_movement_id
 * @property string $reason_code
 * @property string|null $reason_note
 * @property string $reversed_by_user_id
 * @property string $status
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $recorded_at
 * @property int $version
 */
final class DispensingReversal extends AggregateRoot
{
    /** @use HasFactory<DispensingReversalFactory> */
    use HasFactory;

    public const STATUS_COMPLETED = 'COMPLETED';

    protected $table = 'rx_dispensing_reversals';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id',
        'dispensing_id', 'pharmacy_order_id', 'prescription_id', 'patient_id',
        'reversal_number',
        'item_id', 'location_id', 'batch_number', 'quantity',
        'reversal_movement_id', 'reason_code', 'reason_note',
        'reversed_by_user_id', 'status',
        'occurred_at', 'recorded_at', 'version',
    ];

    protected static function newFactory(): DispensingReversalFactory
    {
        return DispensingReversalFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    /**
     * @param  array{
     *     id: string, tenantId: string, facilityId: string, dispensingId: string,
     *     pharmacyOrderId: string, prescriptionId: string, patientId: string,
     *     reversalNumber: string, itemId: string, locationId: string, batchNumber: ?string,
     *     quantity: string, reversalMovementId: string, reasonCode: string, reasonNote: ?string,
     *     reversedByUserId: string, occurredAt: CarbonImmutable, recordedAt: CarbonImmutable
     * }  $data
     */
    public static function record(array $data): self
    {
        $reversal = new self([
            'id' => $data['id'],
            'tenant_id' => $data['tenantId'],
            'facility_id' => $data['facilityId'],
            'dispensing_id' => $data['dispensingId'],
            'pharmacy_order_id' => $data['pharmacyOrderId'],
            'prescription_id' => $data['prescriptionId'],
            'patient_id' => $data['patientId'],
            'reversal_number' => $data['reversalNumber'],
            'item_id' => $data['itemId'],
            'location_id' => $data['locationId'],
            'batch_number' => self::clean($data['batchNumber']),
            'quantity' => $data['quantity'],
            'reversal_movement_id' => $data['reversalMovementId'],
            'reason_code' => $data['reasonCode'],
            'reason_note' => self::clean($data['reasonNote']),
            'reversed_by_user_id' => $data['reversedByUserId'],
            'status' => self::STATUS_COMPLETED,
            'occurred_at' => $data['occurredAt'],
            'recorded_at' => $data['recordedAt'],
        ]);

        $reversal->recordThat(new MedicationDispensingReversed(
            eventId: (string) Str::uuid7(),
            tenantId: $data['tenantId'],
            facilityId: $data['facilityId'],
            patientId: $data['patientId'],
            dispensingReversalId: $data['id'],
            dispensingId: $data['dispensingId'],
            pharmacyOrderId: $data['pharmacyOrderId'],
            prescriptionId: $data['prescriptionId'],
            itemId: $data['itemId'],
            quantity: $data['quantity'],
            reasonCode: $data['reasonCode'],
            reversedByUserId: $data['reversedByUserId'],
            occurredAt: CarbonImmutable::now()->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));

        return $reversal;
    }

    private static function clean(?string $value): ?string
    {
        return $value !== null && trim($value) !== '' ? trim($value) : null;
    }
}
