<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Domain\Model;

use App\Domains\Radiology\Database\Factories\ImagingOrderItemFactory;
use App\Shared\Concurrency\HasVersionColumn;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One requested imaging service on an imaging order (15-radiology-domain.md
 * §18–§19, §106, §181; ADR-165 D-E).
 *
 * Plain Eloquent child model — NOT an AggregateRoot (no order-item event is
 * named anywhere in the domain doc — the `lab_order_items` /
 * `thr_case_team_assignments` precedent). Written directly by
 * `CreateImagingOrderHandler` in the order's transaction. `service_code` /
 * `service_name` / `service_definition_version` / `modality_code` are SNAPSHOTS
 * of the `rad_imaging_services` (and its parent `rad_modalities`) rows at order
 * time. `body_site` / `laterality` snapshot the service defaults.
 *
 * `version` is shape/conformance-only (ConformanceCommand check 3b) — it stays
 * 0 for the row's life and is NOT optimistic concurrency (the standing
 * `governance-rulings` interpretation).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $facility_id
 * @property string $order_id
 * @property string $imaging_service_id
 * @property string $service_code
 * @property string $service_name
 * @property int $service_definition_version
 * @property string $modality_code
 * @property string|null $body_site
 * @property string|null $laterality
 * @property string $status
 * @property int $version
 */
class ImagingOrderItem extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ImagingOrderItemFactory> */
    use HasFactory;

    use HasUuids;
    use HasVersionColumn;

    public const STATUS_ORDERED = 'ORDERED';

    public const STATUS_CANCELLED = 'CANCELLED';

    protected $table = 'rad_imaging_order_items';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'facility_id',
        'order_id',
        'imaging_service_id',
        'service_code',
        'service_name',
        'service_definition_version',
        'modality_code',
        'body_site',
        'laterality',
        'status',
        'version',
    ];

    protected static function newFactory(): ImagingOrderItemFactory
    {
        return ImagingOrderItemFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'service_definition_version' => 'integer',
            'version' => 'integer',
        ];
    }
}
