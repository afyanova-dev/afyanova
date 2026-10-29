<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain\Model;

use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class SupplierReturn extends AggregateRoot
{
    public const REASON_DAMAGED_AFTER_RECEIPT = 'DAMAGED_AFTER_RECEIPT';

    public const REASON_WRONG_ITEM = 'WRONG_ITEM';

    public const REASON_EXPIRED = 'EXPIRED';

    public const STATUS_POSTED = 'POSTED';

    protected $table = 'inv_supplier_returns';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id', 'return_number', 'receipt_id', 'supplier_ref',
        'location_id', 'reason', 'status', 'occurred_at', 'recorded_at', 'version',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public static function record(
        string $id,
        string $tenantId,
        string $facilityId,
        string $returnNumber,
        string $receiptId,
        ?string $supplierRef,
        string $locationId,
        string $reason,
        CarbonImmutable $occurredAt,
        CarbonImmutable $recordedAt,
    ): self {
        foreach (compact('id', 'tenantId', 'facilityId', 'receiptId', 'locationId') as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("SupplierReturn::\${$name} must be a UUID.");
            }
        }
        $reason = strtoupper(trim($reason));
        if (! in_array($reason, [
            self::REASON_DAMAGED_AFTER_RECEIPT,
            self::REASON_WRONG_ITEM,
            self::REASON_EXPIRED,
        ], true)) {
            throw new InvalidArgumentException('SupplierReturn::$reason is not recognised.');
        }

        return new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'return_number' => trim($returnNumber),
            'receipt_id' => $receiptId,
            'supplier_ref' => $supplierRef,
            'location_id' => $locationId,
            'reason' => $reason,
            'status' => self::STATUS_POSTED,
            'occurred_at' => $occurredAt,
            'recorded_at' => $recordedAt,
            'version' => 0,
        ]);
    }
}
