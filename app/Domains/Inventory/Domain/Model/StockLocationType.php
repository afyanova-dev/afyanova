<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain\Model;

use App\Shared\Domain\AggregateRoot;
use InvalidArgumentException;

/**
 * A reusable location classification, tenant-wide or facility-local.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $facility_id
 * @property string $code
 * @property string $name
 * @property string $created_by
 */
final class StockLocationType extends AggregateRoot
{
    protected $table = 'inv_stock_location_types';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'facility_id',
        'code',
        'name',
        'created_by',
    ];

    public static function define(
        string $id,
        string $tenantId,
        ?string $facilityId,
        string $code,
        string $name,
        string $createdBy,
    ): self {
        $normalisedCode = strtoupper(trim($code));
        $trimmedName = trim($name);
        if ($normalisedCode === '' || mb_strlen($normalisedCode) > 100 || ! preg_match('/^[A-Z][A-Z0-9_-]*$/', $normalisedCode)) {
            throw new InvalidArgumentException('Location type code must start with a letter and contain only letters, numbers, underscores, or hyphens.');
        }
        if ($trimmedName === '' || mb_strlen($trimmedName) > 200) {
            throw new InvalidArgumentException('Location type name must be between 1 and 200 characters.');
        }

        return new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'code' => $normalisedCode,
            'name' => $trimmedName,
            'created_by' => $createdBy,
        ]);
    }
}
