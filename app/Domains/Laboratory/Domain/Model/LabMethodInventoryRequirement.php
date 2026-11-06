<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain\Model;

use App\Shared\Domain\AggregateRoot;

/**
 * A versioned snapshot of an Inventory material required by a laboratory method.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $method_id
 * @property int $method_version
 * @property string $inventory_item_id
 * @property string $item_code_snapshot
 * @property string $item_name_snapshot
 * @property string $item_uom_snapshot
 * @property string $item_type_snapshot
 * @property string|null $item_category_snapshot
 * @property string $requirement_role
 * @property bool $required
 * @property string $usage_scope
 * @property string $quantity
 */
final class LabMethodInventoryRequirement extends AggregateRoot
{
    public const ROLES = ['REAGENT', 'KIT', 'CALIBRATOR', 'CONTROL', 'CONSUMABLE'];

    public const USAGE_SCOPES = ['PER_TEST', 'PER_RUN'];

    protected $table = 'lab_method_inventory_requirements';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'method_id',
        'method_version',
        'inventory_item_id',
        'item_code_snapshot',
        'item_name_snapshot',
        'item_uom_snapshot',
        'item_type_snapshot',
        'item_category_snapshot',
        'requirement_role',
        'required',
        'usage_scope',
        'quantity',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['required' => 'boolean'];
    }
}
