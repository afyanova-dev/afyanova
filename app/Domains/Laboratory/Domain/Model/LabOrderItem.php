<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain\Model;

use App\Domains\Laboratory\Database\Factories\LabOrderItemFactory;
use App\Shared\Concurrency\HasVersionColumn;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One requested test on a laboratory order (14-laboratory-domain.md §13, §21,
 * §148–§149; ADR-157 D-D).
 *
 * Plain Eloquent child model — NOT an AggregateRoot (no order-item event is
 * named anywhere in the domain doc — the `thr_case_team_assignments` /
 * Room precedent). Written directly by `CreateLabOrderHandler` in the order's
 * transaction. `test_code` / `test_name` / `test_definition_version` are
 * SNAPSHOTS of the `lab_test_definitions` row at order time (§17).
 *
 * `version` is shape/conformance-only (ConformanceCommand check 3b) — it stays
 * 0 for the row's life and is NOT optimistic concurrency (the standing
 * `governance-rulings` interpretation).
 */
class LabOrderItem extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<LabOrderItemFactory> */
    use HasFactory;

    use HasUuids;
    use HasVersionColumn;

    public const STATUS_ORDERED = 'ORDERED';

    public const STATUS_CANCELLED = 'CANCELLED';

    protected $table = 'lab_order_items';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'facility_id',
        'order_id',
        'test_definition_id',
        'test_code',
        'test_name',
        'test_definition_version',
        'status',
        'ordered_via_panel_id',
        'version',
    ];

    protected static function newFactory(): LabOrderItemFactory
    {
        return LabOrderItemFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'test_definition_version' => 'integer',
            'version' => 'integer',
        ];
    }
}
