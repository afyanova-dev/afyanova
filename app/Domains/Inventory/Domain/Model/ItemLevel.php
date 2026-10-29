<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain\Model;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * The minimum level at which an item is reordered AT ONE FACILITY
 * (frontend-foundation-inventory-operations.md Phase 8, low-stock alerts).
 *
 * Plain Eloquent config row — NOT an AggregateRoot. `min_level` is a Quantity
 * in the item's BASE unit (CAPSULE, not BOX). No row = not tracked; zero is
 * never stored (the table CHECK forbids it), so "off" cannot be mistaken for
 * "reorder at zero".
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $facility_id
 * @property string $item_id
 * @property string $min_level
 */
final class ItemLevel extends Model
{
    use BelongsToTenant;

    protected $table = 'inv_item_levels';

    /**
     * UUID primary key. Without these two, Eloquent treats `id` as an
     * auto-incrementing integer and `->id` / `delete()` use `1` instead of the
     * UUID — 0 clears (deletes) the level, so this must be right.
     */
    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id', 'item_id', 'min_level',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['min_level' => 'decimal:4'];
    }
}
