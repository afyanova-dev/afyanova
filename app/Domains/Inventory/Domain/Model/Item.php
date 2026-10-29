<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain\Model;

use App\Domains\Inventory\Database\Factories\ItemFactory;
use App\Domains\Inventory\Domain\ValueObject\ItemCategory;
use App\Domains\Inventory\Domain\ValueObject\ItemType;
use App\Domains\Inventory\Domain\ValueObject\UnitOfMeasure;
use App\Shared\Domain\AggregateRoot;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use InvalidArgumentException;

/**
 * One Item Master catalog entry — reference data defining a stocked item,
 * separate from the physical stock of it (18-inventory-and-warehousing-domain.md
 * §7, §8, §9; ADR-170 D-C).
 *
 * Tenant-scoped, mutable, integer-versioned reference data — NOT the ADR-132
 * clinical-record shape. Authoring is the `inventory:define-item` console
 * command and the `POST /workspaces/inventory/items` route (both permission-
 * gated). Full catalog governance is deferred ("Inv.0"), exactly as CP.1 → CP.0
 * / Lab.1 → Lab.0 / Rad.1 → Rad.0.
 *
 * `item_type`, `uom`, and `category` are validated against the controlled
 * vocabularies ({@see ItemType} / {@see UnitOfMeasure} / {@see ItemCategory}) —
 * the `BillingServiceCategory` precedent. `item_type` / `uom` are one-way doors
 * once the item has stock history (design: `inventory-item-master-spec.md`).
 *
 * {@see revise()} bumps `version` in place. Historical integrity is the
 * movement's own `uom` SNAPSHOT taken at post time; a retired item keeps its
 * `id` on every historical movement (§182 / Inv 24).
 *
 * @domain      Inventory
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @facility    none — tenant-wide reference data (ADR-170 D-C)
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string|null $category
 * @property string $item_type
 * @property string $uom
 * @property string|null $pack_unit the DEFAULT buying unit (e.g. BOX), null when the item has no pack
 * @property string|null $pack_size how many base units are in one `pack_unit` (e.g. 100)
 * @property bool $track_batch
 * @property bool $track_serial
 * @property bool $track_expiry
 * @property bool $is_controlled
 * @property bool $active
 * @property int $version
 */
final class Item extends AggregateRoot
{
    /** @use HasFactory<ItemFactory> */
    use HasFactory;

    private const MAX_CODE_LENGTH = 100;

    private const MAX_NAME_LENGTH = 200;

    protected $table = 'inv_items';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id',
        'code', 'name', 'description', 'category', 'item_type', 'uom',
        'pack_unit', 'pack_size',
        'track_batch', 'track_serial', 'track_expiry', 'is_controlled',
        'active', 'version',
    ];

    protected static function newFactory(): ItemFactory
    {
        return ItemFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pack_size' => 'decimal:4',
            'track_batch' => 'boolean',
            'track_serial' => 'boolean',
            'track_expiry' => 'boolean',
            'is_controlled' => 'boolean',
            'active' => 'boolean',
        ];
    }

    /**
     * Define a new catalog entry (ADR-170 D-C — the console write path).
     * `$id` is client-minted (a UUIDv7). `version` starts at 1.
     *
     * `$packUnit` / `$packSize` are the item's OPTIONAL default buying unit
     * (e.g. BOX = 100). Give both or neither (§7 pack conversion).
     */
    public static function define(
        string $id,
        string $tenantId,
        string $code,
        string $name,
        string $itemType,
        string $uom,
        ?string $description = null,
        ?string $category = null,
        bool $trackBatch = false,
        bool $trackSerial = false,
        bool $trackExpiry = false,
        bool $isControlled = false,
        ?string $packUnit = null,
        ?string $packSize = null,
    ): self {
        if ($trackExpiry && ! $trackBatch) {
            throw new InvalidArgumentException(
                'Item::$trackExpiry requires $trackBatch — expiry belongs to the batch (§43 / Inv 28; ADR-170 D-C).'
            );
        }

        $baseUom = UnitOfMeasure::assert($uom);
        [$packUnit, $packSize] = self::assertPack($packUnit, $packSize, $baseUom);

        return new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'code' => self::assertCode($code),
            'name' => self::assertName($name),
            'item_type' => ItemType::assert($itemType),
            'uom' => $baseUom,
            'pack_unit' => $packUnit,
            'pack_size' => $packSize,
            'description' => self::blankToNull($description),
            'category' => self::assertCategory($category),
            'track_batch' => $trackBatch,
            'track_serial' => $trackSerial,
            'track_expiry' => $trackExpiry,
            'is_controlled' => $isControlled,
            'active' => true,
            'version' => 1,
        ]);
    }

    /**
     * Update this item in place (ADR-170 D-C — the console write path ONLY).
     * Bumps `version`. `null` for a parameter leaves the current value
     * unchanged. Named `revise()`, not `update()` (the CP.1 / Lab.1 / Rad.1
     * precedent — `update()` is Eloquent's).
     */
    public function revise(
        ?string $name = null,
        ?string $description = null,
        ?string $category = null,
        ?string $itemType = null,
        ?string $uom = null,
        ?bool $trackBatch = null,
        ?bool $trackSerial = null,
        ?bool $trackExpiry = null,
        ?bool $isControlled = null,
        ?bool $active = null,
        ?string $packUnit = null,
        ?string $packSize = null,
    ): void {
        if ($name !== null) {
            $this->name = self::assertName($name);
        }
        if ($description !== null) {
            $this->description = self::blankToNull($description);
        }
        if ($category !== null) {
            $this->category = self::assertCategory($category);
        }
        if ($itemType !== null) {
            $this->item_type = ItemType::assert($itemType);
        }
        if ($uom !== null) {
            $this->uom = UnitOfMeasure::assert($uom);
        }
        if ($trackBatch !== null) {
            $this->track_batch = $trackBatch;
        }
        if ($trackSerial !== null) {
            $this->track_serial = $trackSerial;
        }
        if ($trackExpiry !== null) {
            $this->track_expiry = $trackExpiry;
        }
        if ($isControlled !== null) {
            $this->is_controlled = $isControlled;
        }
        if ($active !== null) {
            $this->active = $active;
        }
        if ($packUnit !== null) {
            $this->pack_unit = self::blankToNull($packUnit);
        }
        if ($packSize !== null) {
            $this->pack_size = self::blankToNull($packSize);
        }

        // The pack is a pair, and it must not name the base unit as its own
        // buying unit — re-checked unconditionally so changing `uom` cannot
        // leave a pack that collides with it.
        [$packUnit, $packSize] = self::assertPack($this->pack_unit, $this->pack_size, (string) $this->uom);
        $this->pack_unit = $packUnit;
        $this->pack_size = $packSize;

        if ((bool) $this->track_expiry && ! (bool) $this->track_batch) {
            throw new InvalidArgumentException(
                'Item::$trackExpiry requires $trackBatch — expiry belongs to the batch (§43 / Inv 28; ADR-170 D-C).'
            );
        }

        $this->version = (int) $this->version + 1;
    }

    private static function assertCode(string $code): string
    {
        $trimmed = trim($code);

        if ($trimmed === '') {
            throw new InvalidArgumentException('Item::$code must not be blank (ADR-170 D-C).');
        }

        if (mb_strlen($trimmed) > self::MAX_CODE_LENGTH) {
            throw new InvalidArgumentException(sprintf('Item::$code must be at most %d characters.', self::MAX_CODE_LENGTH));
        }

        return strtoupper($trimmed);
    }

    private static function assertName(string $name): string
    {
        $trimmed = trim($name);

        if ($trimmed === '') {
            throw new InvalidArgumentException('Item::$name must not be blank (ADR-170 D-C).');
        }

        if (mb_strlen($trimmed) > self::MAX_NAME_LENGTH) {
            throw new InvalidArgumentException(sprintf('Item::$name must be at most %d characters.', self::MAX_NAME_LENGTH));
        }

        return $trimmed;
    }

    /**
     * Validate the item's pack pair — `pack_unit` ⟺ `pack_size`
     * (`inventory-item-master-spec.md` §7). Both null means "no pack", which is
     * most items. Both set means "bought in this unit, this many base units
     * each". A pack that names the base unit, or holds <= 1 of it, is not a
     * pack — the base unit must always be the smallest unit counted.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private static function assertPack(?string $packUnit, ?string $packSize, string $baseUom): array
    {
        $unit = self::blankToNull($packUnit);
        $size = self::blankToNull($packSize);

        if ($unit === null && $size === null) {
            return [null, null];
        }

        if ($unit === null || $size === null) {
            throw new InvalidArgumentException(
                'Item::$packUnit and Item::$packSize must be given together — the buying unit and how many base units it holds.'
            );
        }

        $unit = UnitOfMeasure::assert($unit);

        if ($unit === $baseUom) {
            throw new InvalidArgumentException(sprintf(
                'Item::$packUnit [%s] must differ from the item\'s base unit — a pack of one base unit is not a pack.',
                $unit,
            ));
        }

        if (! is_numeric($size) || bccomp($size, '1', 4) <= 0) {
            throw new InvalidArgumentException('Item::$packSize must be a number greater than 1 (base units per pack).');
        }

        return [$unit, bcadd($size, '0', 4)];
    }

    private static function assertCategory(?string $value): ?string
    {
        $trimmed = self::blankToNull($value);

        return $trimmed === null ? null : ItemCategory::assert($trimmed);
    }

    private static function blankToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
