<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Domain\Model;

use App\Domains\Radiology\Database\Factories\ImagingModalityFactory;
use App\Shared\Domain\AggregateRoot;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use InvalidArgumentException;

/**
 * One imaging modality catalog entry — an imaging technology / examination
 * method (X-Ray, CT, MRI …), separate from the patient-facing imaging SERVICE
 * offered on it and from any ORDER / STUDY / REPORT
 * (15-radiology-domain.md §11, §13, §185, §187; ADR-165 D-C).
 *
 * Tenant-scoped, mutable, integer-versioned reference data — NOT the ADR-132
 * clinical-record shape. Authoring is the `radiology:define-modality` console
 * command ONLY (ADR-165 D-C) — no HTTP command, no permission key. Full catalog
 * governance is deferred ("Rad.0"), exactly as CP.1 → CP.0 and Lab.1 → Lab.0.
 *
 * NOT immutable / NOT superseding: {@see revise()} bumps `version` in place.
 * Historical integrity is the ORDER ITEM's `service_definition_version` +
 * `modality_code` SNAPSHOT, taken at `CreateImagingOrder`.
 *
 * @domain      Radiology
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @facility    none — tenant-wide reference data (ADR-165 D-C)
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property string|null $category
 * @property string|null $description
 * @property string|null $required_capability_code
 * @property string $operational_status
 * @property bool $active
 * @property int $version
 */
final class ImagingModality extends AggregateRoot
{
    /** @use HasFactory<ImagingModalityFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_SUSPENDED = 'SUSPENDED';

    private const MAX_CODE_LENGTH = 100;

    private const MAX_NAME_LENGTH = 200;

    protected $table = 'rad_modalities';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id',
        'code', 'name', 'category', 'description',
        'required_capability_code', 'operational_status',
        'active', 'version',
    ];

    protected static function newFactory(): ImagingModalityFactory
    {
        return ImagingModalityFactory::new();
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
     * Define a new catalog entry (ADR-165 D-C — the console write path).
     * `$id` is client-minted (a UUIDv7). `version` starts at 1.
     */
    public static function define(
        string $id,
        string $tenantId,
        string $code,
        string $name,
        ?string $category = null,
        ?string $description = null,
        ?string $requiredCapabilityCode = null,
        string $operationalStatus = self::STATUS_ACTIVE,
    ): self {
        return new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'code' => self::assertCode($code),
            'name' => self::assertName($name),
            'category' => self::blankToNull($category),
            'description' => self::blankToNull($description),
            'required_capability_code' => self::blankToNull($requiredCapabilityCode),
            'operational_status' => self::normaliseStatus($operationalStatus),
            'active' => true,
            'version' => 1,
        ]);
    }

    /**
     * Update this modality in place (ADR-165 D-C — the console write path ONLY).
     * Bumps `version`. `null` for a parameter leaves the current value unchanged.
     *
     * Named `revise()`, not `update()` — `update()` is Eloquent
     * `Model::update(array, array)` (the CP.1 / Lab.1 precedent).
     */
    public function revise(
        ?string $name = null,
        ?string $category = null,
        ?string $description = null,
        ?string $requiredCapabilityCode = null,
        ?string $operationalStatus = null,
        ?bool $active = null,
    ): void {
        if ($name !== null) {
            $this->name = self::assertName($name);
        }
        if ($category !== null) {
            $this->category = self::blankToNull($category);
        }
        if ($description !== null) {
            $this->description = self::blankToNull($description);
        }
        if ($requiredCapabilityCode !== null) {
            $this->required_capability_code = self::blankToNull($requiredCapabilityCode);
        }
        if ($operationalStatus !== null) {
            $this->operational_status = self::normaliseStatus($operationalStatus);
        }
        if ($active !== null) {
            $this->active = $active;
        }

        $this->version = (int) $this->version + 1;
    }

    /** Can an imaging service on this modality accept a new order line? (§139 / §198 / §209 Inv 31). */
    public function isOperational(): bool
    {
        return (bool) $this->active
            && $this->operational_status !== self::STATUS_SUSPENDED;
    }

    private static function assertCode(string $code): string
    {
        $trimmed = trim($code);

        if ($trimmed === '') {
            throw new InvalidArgumentException('ImagingModality::$code must not be blank (ADR-165 D-C).');
        }

        if (mb_strlen($trimmed) > self::MAX_CODE_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'ImagingModality::$code must be at most %d characters (ADR-165 D-C).',
                self::MAX_CODE_LENGTH,
            ));
        }

        return strtoupper($trimmed);
    }

    private static function assertName(string $name): string
    {
        $trimmed = trim($name);

        if ($trimmed === '') {
            throw new InvalidArgumentException('ImagingModality::$name must not be blank (ADR-165 D-C).');
        }

        if (mb_strlen($trimmed) > self::MAX_NAME_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'ImagingModality::$name must be at most %d characters (ADR-165 D-C).',
                self::MAX_NAME_LENGTH,
            ));
        }

        return $trimmed;
    }

    private static function normaliseStatus(string $status): string
    {
        $normalised = strtoupper(trim($status));

        if ($normalised === '') {
            throw new InvalidArgumentException('ImagingModality::$operationalStatus must not be blank (§139).');
        }

        return $normalised;
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
