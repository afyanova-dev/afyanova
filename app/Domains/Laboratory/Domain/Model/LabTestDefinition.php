<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain\Model;

use App\Domains\Laboratory\Database\Factories\LabTestDefinitionFactory;
use App\Shared\Domain\AggregateRoot;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use InvalidArgumentException;

/**
 * One laboratory test catalog entry — WHAT a laboratory investigation is,
 * separate from the ORDER for it and the RESULT it produces
 * (14-laboratory-domain.md §12–§17, §124–§125, §191; ADR-157 D-C).
 *
 * Tenant-scoped, mutable, integer-versioned reference data — NOT the ADR-132
 * clinical-record shape (no patient / encounter / facility / author / clinical
 * time). Authoring is the `laboratory:define-test` console command ONLY
 * (ADR-157 D-C) — there is NO HTTP `DefineLabTest` bus command and NO
 * `laboratory.catalog.manage` permission key in Lab.1. Catalog approval /
 * maker-checker / effective-dating is deferred (working name Lab.0), exactly as
 * CP.1 deferred it to CP.0.
 *
 * NOT immutable / NOT superseding: {@see revise()} bumps `version` in place.
 * Historical integrity is the ORDER ITEM's `test_definition_version` SNAPSHOT
 * (`LabOrderItem::$test_definition_version`), taken at `CreateLabOrder` (§17).
 *
 * Facility availability is `required_capability_code` +
 * `App\Shared\Capability\FacilityCapabilities::has()` at `CreateLabOrder`
 * (§18, §115; ADR-120) — this catalog itself is tenant-wide, NOT per-facility.
 *
 * @domain      Laboratory
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @facility    none — tenant-wide reference data (ADR-157 D-C)
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property string|null $category
 * @property string|null $description
 * @property string|null $specimen_type
 * @property string|null $container_type
 * @property list<array{code: string, name?: string|null, unit?: string|null}>|null $parameters
 * @property string|null $required_capability_code
 * @property string $availability_status
 * @property int|null $turnaround_target_minutes
 * @property bool $active
 * @property int $version
 */
final class LabTestDefinition extends AggregateRoot
{
    /** @use HasFactory<LabTestDefinitionFactory> */
    use HasFactory;

    public const AVAILABILITY_AVAILABLE = 'AVAILABLE';

    public const AVAILABILITY_TEMPORARILY_UNAVAILABLE = 'TEMPORARILY_UNAVAILABLE';

    public const AVAILABILITY_REFERRAL_ONLY = 'REFERRAL_ONLY';

    public const AVAILABILITY_DISABLED = 'DISABLED';

    private const MAX_CODE_LENGTH = 100;

    private const MAX_NAME_LENGTH = 200;

    protected $table = 'lab_test_definitions';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id',
        'code', 'name', 'category', 'description',
        'specimen_type', 'container_type', 'parameters',
        'required_capability_code', 'availability_status', 'turnaround_target_minutes',
        'active', 'version',
    ];

    protected static function newFactory(): LabTestDefinitionFactory
    {
        return LabTestDefinitionFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'turnaround_target_minutes' => 'integer',
            'active' => 'boolean',
        ];
    }

    /**
     * Define a new catalog entry (ADR-157 D-C — the console write path).
     * `$id` is client-minted (a UUIDv7). `version` starts at 1.
     *
     * @param  list<array{code: string, name?: string|null, unit?: string|null}>|null  $parameters
     */
    public static function define(
        string $id,
        string $tenantId,
        string $code,
        string $name,
        ?string $category = null,
        ?string $description = null,
        ?string $specimenType = null,
        ?string $containerType = null,
        ?array $parameters = null,
        ?string $requiredCapabilityCode = null,
        string $availabilityStatus = self::AVAILABILITY_AVAILABLE,
        ?int $turnaroundTargetMinutes = null,
    ): self {
        return new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'code' => self::assertCode($code),
            'name' => self::assertName($name),
            'category' => self::blankToNull($category),
            'description' => self::blankToNull($description),
            'specimen_type' => self::normaliseCode($specimenType),
            'container_type' => self::normaliseCode($containerType),
            'parameters' => $parameters,
            'required_capability_code' => self::blankToNull($requiredCapabilityCode),
            'availability_status' => self::assertAvailability($availabilityStatus),
            'turnaround_target_minutes' => $turnaroundTargetMinutes,
            'active' => true,
            'version' => 1,
        ]);
    }

    /**
     * Update this definition in place (ADR-157 D-C — the console write path
     * ONLY). Bumps `version`. `null` for an optional parameter leaves the
     * current value unchanged; pass an explicit value to change it.
     *
     * Named `revise()`, not `update()` — `update()` is Eloquent
     * `Model::update(array, array)` and cannot take an incompatible signature
     * (the CP.1 `ProcedureDefinition::revise()` precedent).
     *
     * @param  list<array{code: string, name?: string|null, unit?: string|null}>|null  $parameters
     */
    public function revise(
        ?string $name = null,
        ?string $category = null,
        ?string $description = null,
        ?string $specimenType = null,
        ?string $containerType = null,
        ?array $parameters = null,
        ?string $requiredCapabilityCode = null,
        ?string $availabilityStatus = null,
        ?int $turnaroundTargetMinutes = null,
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
        if ($specimenType !== null) {
            $this->specimen_type = self::normaliseCode($specimenType);
        }
        if ($containerType !== null) {
            $this->container_type = self::normaliseCode($containerType);
        }
        if ($parameters !== null) {
            $this->parameters = $parameters;
        }
        if ($requiredCapabilityCode !== null) {
            $this->required_capability_code = self::blankToNull($requiredCapabilityCode);
        }
        if ($availabilityStatus !== null) {
            $this->availability_status = self::assertAvailability($availabilityStatus);
        }
        if ($turnaroundTargetMinutes !== null) {
            $this->turnaround_target_minutes = $turnaroundTargetMinutes;
        }
        if ($active !== null) {
            $this->active = $active;
        }

        $this->version = (int) $this->version + 1;
    }

    /** Can a new order line be placed against this definition? (§18–§20, §27 Inv). */
    public function isOrderable(): bool
    {
        return (bool) $this->active
            && $this->availability_status !== self::AVAILABILITY_DISABLED;
    }

    private static function assertCode(string $code): string
    {
        $trimmed = trim($code);

        if ($trimmed === '') {
            throw new InvalidArgumentException('LabTestDefinition::$code must not be blank (ADR-157 D-C).');
        }

        if (mb_strlen($trimmed) > self::MAX_CODE_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'LabTestDefinition::$code must be at most %d characters (ADR-157 D-C).',
                self::MAX_CODE_LENGTH,
            ));
        }

        return $trimmed;
    }

    private static function assertName(string $name): string
    {
        $trimmed = trim($name);

        if ($trimmed === '') {
            throw new InvalidArgumentException('LabTestDefinition::$name must not be blank (ADR-157 D-C).');
        }

        if (mb_strlen($trimmed) > self::MAX_NAME_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'LabTestDefinition::$name must be at most %d characters (ADR-157 D-C).',
                self::MAX_NAME_LENGTH,
            ));
        }

        return $trimmed;
    }

    private static function assertAvailability(string $status): string
    {
        $normalised = strtoupper(trim($status));

        if ($normalised === '') {
            throw new InvalidArgumentException('LabTestDefinition::$availabilityStatus must not be blank (§20).');
        }

        return $normalised;
    }

    private static function normaliseCode(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = strtoupper(trim($value));

        return $trimmed === '' ? null : $trimmed;
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
