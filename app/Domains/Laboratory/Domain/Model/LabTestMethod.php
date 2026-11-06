<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain\Model;

use App\Shared\Domain\AggregateRoot;
use InvalidArgumentException;

/**
 * A tenant-wide way to perform a test, versioned with its material requirements.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $test_definition_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string|null $specimen_type
 * @property string|null $container_type
 * @property bool $active
 * @property int $version
 */
final class LabTestMethod extends AggregateRoot
{
    protected $table = 'lab_test_methods';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'test_definition_id',
        'code',
        'name',
        'description',
        'specimen_type',
        'container_type',
        'active',
        'version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function revise(
        string $name,
        ?string $description,
        ?string $specimenType,
        ?string $containerType,
        bool $active,
    ): void {
        $this->name = self::assertNotBlank($name, 'name');
        $this->description = self::blankToNull($description);
        $this->specimen_type = self::blankToNull($specimenType);
        $this->container_type = self::blankToNull($containerType);
        $this->active = $active;
        $this->version = (int) $this->version + 1;
    }

    private static function assertNotBlank(string $value, string $field): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException("Laboratory method {$field} must not be blank.");
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
