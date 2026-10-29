<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain\Model;

use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One line of a goods receipt (GRN).
 *
 * `quantity_accepted` is a PostgreSQL generated column
 * (`quantity_delivered - quantity_rejected`); it is never written by the
 * application. Only accepted quantity produces an `inv_stock_movements` row,
 * so a fully rejected line has a null `movement_id`.
 *
 * This is a plain child of {@see GoodsReceipt}, not an AggregateRoot. It is
 * written in the same command transaction as the receipt header and posted
 * stock movements.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $receipt_id
 * @property string $item_id
 * @property string|null $po_line_ref
 * @property string|null $batch_id
 * @property string|null $movement_id
 * @property string $entered_unit
 * @property string $entered_quantity
 * @property string $pack_size
 * @property string $quantity_delivered
 * @property string $quantity_rejected
 * @property string $quantity_accepted
 * @property string|null $po_unit
 * @property string|null $po_unit_price
 * @property string|null $invoice_quantity
 * @property string|null $invoice_unit_price
 * @property string $uom
 * @property string|null $batch_number
 * @property CarbonImmutable|null $expiry_date
 * @property CarbonImmutable|null $manufacture_date
 * @property string $condition
 * @property string|null $substituted_for_item_id
 * @property string|null $condition_notes
 * @property string|null $rejection_reason
 */
final class GoodsReceiptLine extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const CONDITION_GOOD = 'GOOD';

    public const CONDITION_DAMAGED = 'DAMAGED';

    public const CONDITION_SHORT = 'SHORT';

    public const CONDITION_NEAR_EXPIRY = 'NEAR_EXPIRY';

    public const CONDITION_WRONG_ITEM = 'WRONG_ITEM';

    protected $table = 'inv_goods_receipt_lines';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'receipt_id',
        'item_id', 'po_line_ref', 'batch_id', 'movement_id',
        'entered_unit', 'entered_quantity', 'pack_size',
        'quantity_delivered', 'quantity_rejected', 'uom',
        'po_unit', 'po_unit_price', 'invoice_quantity', 'invoice_unit_price',
        'batch_number', 'expiry_date', 'manufacture_date',
        'condition', 'substituted_for_item_id', 'condition_notes', 'rejection_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entered_quantity' => 'decimal:4',
            'pack_size' => 'decimal:4',
            'quantity_delivered' => 'decimal:4',
            'quantity_rejected' => 'decimal:4',
            'quantity_accepted' => 'decimal:4',
            'po_unit_price' => 'decimal:2',
            'invoice_quantity' => 'decimal:4',
            'invoice_unit_price' => 'decimal:2',
            'expiry_date' => 'immutable_date',
            'manufacture_date' => 'immutable_date',
        ];
    }

    public static function isKnownCondition(string $condition): bool
    {
        return in_array($condition, [
            self::CONDITION_GOOD,
            self::CONDITION_DAMAGED,
            self::CONDITION_SHORT,
            self::CONDITION_NEAR_EXPIRY,
            self::CONDITION_WRONG_ITEM,
        ], true);
    }
}
