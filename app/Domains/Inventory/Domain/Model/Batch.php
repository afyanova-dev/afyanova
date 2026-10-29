<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain\Model;

use App\Domains\Inventory\Database\Factories\BatchFactory;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * One Batch / Lot record (18-inventory-and-warehousing-domain.md §39, §42,
 * §43, §247; ADR-170 D-E).
 *
 * An AggregateRoot (§247 "Batch Aggregate") — aggregate-0 shape, no events in
 * Inv.1. `batch_number` is meaningful only per item (§39). Tenant-scoped, NOT
 * facility-scoped. `supplier_ref` is an OPAQUE nullable string, no `pcr_` FK.
 * Batch STATUS + the quarantine / recall lifecycle arrive with Inv.6.
 *
 * @domain      Inventory
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @facility    none
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $item_id
 * @property string $batch_number
 * @property CarbonImmutable|null $manufacture_date
 * @property CarbonImmutable|null $expiry_date
 * @property string|null $supplier_ref
 * @property CarbonImmutable $received_at
 * @property int $version
 */
final class Batch extends AggregateRoot
{
    /** @use HasFactory<BatchFactory> */
    use HasFactory;

    protected $table = 'inv_batches';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'item_id',
        'batch_number', 'manufacture_date', 'expiry_date', 'supplier_ref', 'received_at',
        'version',
    ];

    protected static function newFactory(): BatchFactory
    {
        return BatchFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'manufacture_date' => 'immutable_date',
            'expiry_date' => 'immutable_date',
            'received_at' => 'immutable_datetime',
        ];
    }

    /**
     * Register a lot on first receipt (ADR-170 D-E). Called find-or-create by
     * (item_id, batch_number) inside `ReceiveStock`.
     */
    public static function register(
        string $tenantId,
        string $itemId,
        string $batchNumber,
        ?string $expiryDate,
        ?string $manufactureDate,
        ?string $supplierRef,
        CarbonImmutable $receivedAt,
    ): self {
        $number = trim($batchNumber);

        if ($number === '') {
            throw new InvalidArgumentException('Batch::$batchNumber must not be blank (§39; ADR-170 D-E).');
        }

        return new self([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'item_id' => $itemId,
            'batch_number' => $number,
            'expiry_date' => $expiryDate !== null && trim($expiryDate) !== '' ? CarbonImmutable::parse($expiryDate) : null,
            'manufacture_date' => $manufactureDate !== null && trim($manufactureDate) !== '' ? CarbonImmutable::parse($manufactureDate) : null,
            'supplier_ref' => $supplierRef !== null && trim($supplierRef) !== '' ? trim($supplierRef) : null,
            'received_at' => $receivedAt,
        ]);
    }

    /**
     * Expiry eligibility (ADR-170 D-E; the Phase 4 expiry-eligibility decision).
     *
     * A batch is usable through the END of its `expiry_date`; it is expired from
     * the following day. A batch with no recorded expiry is never expired here.
     * This is the single eligibility rule the availability reader and the
     * reserve / consume gates share — a frontend warning is not an expiry
     * control. Batch STATUS (quarantine / recall, Inv.6) is not modelled yet;
     * when it lands, ineligibility extends THIS method, not its callers.
     */
    public function isExpired(?CarbonImmutable $asOf = null): bool
    {
        if ($this->expiry_date === null) {
            return false;
        }

        return $this->expiry_date->lessThan(($asOf ?? CarbonImmutable::now())->startOfDay());
    }
}
