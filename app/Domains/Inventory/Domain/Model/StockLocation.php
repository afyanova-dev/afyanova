<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain\Model;

use App\Domains\Inventory\Database\Factories\StockLocationFactory;
use App\Shared\Domain\AggregateRoot;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use InvalidArgumentException;

/**
 * One facility-scoped stock location with a type code selected from the built-in
 * Warehouse/Store types or an authorized tenant/facility location-type catalog.
 *
 * @domain      Inventory
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
 * @property string|null $department_id
 * @property string $code
 * @property string $name
 * @property string $location_type
 * @property string|null $parent_location_id
 * @property bool $active
 * @property int $version
 */
final class StockLocation extends AggregateRoot
{
    /** @use HasFactory<StockLocationFactory> */
    use HasFactory;

    public const TYPE_WAREHOUSE = 'WAREHOUSE';

    public const TYPE_STORE = 'STORE';

    public const TYPE_QUARANTINE = 'QUARANTINE';

    public const TYPE_LOCATION = 'LOCATION';

    public const TYPE_BIN = 'BIN';

    private const MAX_CODE_LENGTH = 100;

    private const MAX_NAME_LENGTH = 200;

    protected $table = 'inv_stock_locations';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id',
        'department_id',
        'code', 'name', 'location_type', 'parent_location_id',
        'active', 'version',
    ];

    protected static function newFactory(): StockLocationFactory
    {
        return StockLocationFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    /**
     * Define a new stock location (ADR-170 D-D — the console write path).
     * `version` starts at 1.
     */
    public static function define(
        string $id,
        string $tenantId,
        string $facilityId,
        string $code,
        string $name,
        string $locationType,
        ?string $parentLocationId = null,
        ?string $departmentId = null,
    ): self {
        return new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'department_id' => $departmentId,
            'code' => self::assertCode($code),
            'name' => self::assertName($name),
            'location_type' => self::normaliseType($locationType),
            'parent_location_id' => $parentLocationId,
            'active' => true,
            'version' => 1,
        ]);
    }

    /**
     * Update this location in place (ADR-170 D-D — the console write path ONLY).
     * Bumps `version`. `null` for a parameter leaves the current value
     * unchanged.
     */
    public function revise(
        ?string $name = null,
        ?string $locationType = null,
        ?string $parentLocationId = null,
        ?bool $active = null,
        ?string $departmentId = null,
    ): void {
        if ($name !== null) {
            $this->name = self::assertName($name);
        }
        if ($locationType !== null) {
            $this->location_type = self::normaliseType($locationType);
        }
        if ($parentLocationId !== null) {
            $this->parent_location_id = $parentLocationId !== '' ? $parentLocationId : null;
        }
        if ($active !== null) {
            $this->active = $active;
        }
        if ($departmentId !== null) {
            $this->department_id = $departmentId !== '' ? $departmentId : null;
        }

        $this->version = (int) $this->version + 1;
    }

    private static function assertCode(string $code): string
    {
        $trimmed = trim($code);

        if ($trimmed === '') {
            throw new InvalidArgumentException('StockLocation::$code must not be blank (ADR-170 D-D).');
        }

        if (mb_strlen($trimmed) > self::MAX_CODE_LENGTH) {
            throw new InvalidArgumentException(sprintf('StockLocation::$code must be at most %d characters.', self::MAX_CODE_LENGTH));
        }

        return strtoupper($trimmed);
    }

    private static function assertName(string $name): string
    {
        $trimmed = trim($name);

        if ($trimmed === '') {
            throw new InvalidArgumentException('StockLocation::$name must not be blank (ADR-170 D-D).');
        }

        if (mb_strlen($trimmed) > self::MAX_NAME_LENGTH) {
            throw new InvalidArgumentException(sprintf('StockLocation::$name must be at most %d characters.', self::MAX_NAME_LENGTH));
        }

        return $trimmed;
    }

    private static function normaliseType(string $type): string
    {
        $normalised = strtoupper(trim($type));

        if ($normalised === '' || ! preg_match('/^[A-Z][A-Z0-9_-]*$/', $normalised)) {
            throw new InvalidArgumentException(
                'StockLocation::$locationType must be a valid location type code.'
            );
        }

        return $normalised;
    }
}
