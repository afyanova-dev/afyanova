<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain\Model;

use App\Shared\Domain\AggregateRoot;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/**
 * A facility's operational instrument identity, separate from Inventory stock.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $facility_id
 * @property string $identifier
 * @property string $name
 * @property string|null $manufacturer
 * @property string|null $model
 * @property string|null $serial_number
 * @property string|null $inventory_item_id
 * @property string|null $inventory_code_snapshot
 * @property string|null $inventory_name_snapshot
 * @property string $status
 * @property int $version
 */
final class LabFacilityInstrument extends AggregateRoot
{
    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_OUT_OF_SERVICE = 'OUT_OF_SERVICE';

    public const STATUS_DECOMMISSIONED = 'DECOMMISSIONED';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_OUT_OF_SERVICE,
        self::STATUS_DECOMMISSIONED,
    ];

    protected $table = 'lab_facility_instruments';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'identifier',
        'name',
        'manufacturer',
        'model',
        'serial_number',
        'inventory_item_id',
        'inventory_code_snapshot',
        'inventory_name_snapshot',
        'status',
    ];

    /**
     * @param  array{
     *     tenantId: string,
     *     facilityId: string,
     *     identifier: string,
     *     name: string,
     *     manufacturer: ?string,
     *     model: ?string,
     *     serialNumber: ?string,
     *     inventoryItemId: ?string,
     *     inventoryCodeSnapshot: ?string,
     *     inventoryNameSnapshot: ?string
     * }  $data
     */
    public static function register(array $data): self
    {
        return new self([
            'tenant_id' => $data['tenantId'],
            'facility_id' => $data['facilityId'],
            'identifier' => self::required($data['identifier'], 'identifier'),
            'name' => self::required($data['name'], 'name'),
            'manufacturer' => self::blankToNull($data['manufacturer']),
            'model' => self::blankToNull($data['model']),
            'serial_number' => self::blankToNull($data['serialNumber']),
            'inventory_item_id' => $data['inventoryItemId'],
            'inventory_code_snapshot' => $data['inventoryCodeSnapshot'],
            'inventory_name_snapshot' => $data['inventoryNameSnapshot'],
            'status' => self::STATUS_ACTIVE,
        ]);
    }

    /**
     * @param  array{
     *     identifier: string,
     *     name: string,
     *     manufacturer: ?string,
     *     model: ?string,
     *     serialNumber: ?string,
     *     inventoryItemId: ?string,
     *     inventoryCodeSnapshot: ?string,
     *     inventoryNameSnapshot: ?string,
     *     status: string
     * }  $data
     */
    public function revise(array $data): void
    {
        if ($this->status === self::STATUS_DECOMMISSIONED && $data['status'] !== self::STATUS_DECOMMISSIONED) {
            throw new InvalidArgumentException('A decommissioned laboratory instrument cannot be reactivated.');
        }

        $this->identifier = self::required($data['identifier'], 'identifier');
        $this->name = self::required($data['name'], 'name');
        $this->manufacturer = self::blankToNull($data['manufacturer']);
        $this->model = self::blankToNull($data['model']);
        $this->serial_number = self::blankToNull($data['serialNumber']);
        $this->inventory_item_id = $data['inventoryItemId'];
        $this->inventory_code_snapshot = $data['inventoryCodeSnapshot'];
        $this->inventory_name_snapshot = $data['inventoryNameSnapshot'];
        $this->status = $data['status'];
        $this->version = (int) $this->version + 1;
    }

    /**
     * @return HasMany<LabFacilityInstrumentMethod, $this>
     */
    public function supportedMethods(): HasMany
    {
        return $this->hasMany(LabFacilityInstrumentMethod::class, 'instrument_id');
    }

    private static function required(string $value, string $field): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException("Laboratory instrument {$field} must not be blank.");
        }

        return $value;
    }

    private static function blankToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
