<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Model;

use App\Domains\Procurement\Database\Factories\QuotationLineFactory;
use App\Domains\Procurement\Domain\Money;
use App\Shared\Concurrency\HasVersionColumn;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One line on a supplier quotation (19-procurement-domain.md §32, §34, §39;
 * ADR-173 D-C).
 *
 * Plain Eloquent child — NOT an AggregateRoot. Cites an `rfq_line_id` (a
 * supplier need not quote every line). `unit_price` is `Money` (scale 2);
 * `offered_qty` is `Quantity` (scale 4) — MAY be < the RFQ line quantity (§39
 * partial). `line_total` = `unit_price * offered_qty + tax − discount` is
 * COMPUTED via {@see lineTotal()}, never stored (the ADR-171 F13 lesson).
 * `version` shape / conformance-only — stays 0.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $facility_id
 * @property string $quotation_id
 * @property string $rfq_line_id
 * @property int $line_no
 * @property string $unit_price
 * @property string $offered_qty
 * @property string|null $tax_amount
 * @property string|null $discount_amount
 * @property string|null $line_note
 * @property int $version
 */
class QuotationLine extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<QuotationLineFactory> */
    use HasFactory;

    use HasUuids;
    use HasVersionColumn;

    protected $table = 'pcr_quotation_lines';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id',
        'quotation_id', 'rfq_line_id', 'line_no',
        'unit_price', 'offered_qty', 'tax_amount', 'discount_amount', 'line_note',
        'version',
    ];

    protected static function newFactory(): QuotationLineFactory
    {
        return QuotationLineFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'unit_price' => 'decimal:2',
            'offered_qty' => 'decimal:4',
            'tax_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'version' => 'integer',
        ];
    }

    /** unit_price × offered_qty + tax − discount, at money scale — computed, never stored (F13). */
    public function lineTotal(): string
    {
        $base = Money::multiplyByQuantity((string) $this->unit_price, (string) $this->offered_qty);
        $withTax = Money::add($base, $this->tax_amount !== null ? (string) $this->tax_amount : '0');

        return Money::subtract($withTax, $this->discount_amount !== null ? (string) $this->discount_amount : '0');
    }
}
