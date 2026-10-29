<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\Model;

use App\Domains\Clinical\Database\Factories\ProcedureDefinitionFactory;
use App\Shared\Domain\AggregateRoot;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use InvalidArgumentException;

/**
 * One procedure catalog entry — WHAT a procedure is, separate from the
 * INSTANCE describing this procedure performed on this patient
 * (13-clinical-procedures-domain.md §11–§13, §111–§112; ADR-137, ADR-146 D-C /
 * D-D).
 *
 * Tenant-scoped, mutable, integer-versioned reference data — NOT the ADR-132
 * clinical-record shape (no patient / encounter / facility / author / clinical
 * time; ADR-146 D-D). Authoring is a database seeder + the
 * `clinical:define-procedure` console command ONLY (ADR-146 D-C) — there is NO
 * HTTP `DefineProcedure` / `UpdateProcedureDefinition` bus command and NO
 * `clinical.procedures.manage_catalog` permission key in CP.1. Catalog
 * approval / maker-checker / effective-dating is deferred (working name CP.0).
 *
 * NOT immutable / NOT superseding: {@see revise()} bumps `version` in place.
 * Historical integrity is the INSTANCE's `definition_version` SNAPSHOT
 * (`ClinicalProcedure::$definition_version`), taken at `RequestProcedure`
 * (13 §112) — never a copy of this row's body.
 *
 * Facility availability is `required_capability_code` +
 * `App\Shared\Capability\FacilityCapabilities::has()` at `RequestProcedure`
 * (13 §22–§23; ADR-120) — this catalog itself is tenant-wide, NOT per-facility.
 *
 * @domain      Clinical
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @facility    none — tenant-wide reference data (ADR-146 D-C)
 *
 * @property list<array{material_ref: string, default_quantity?: float|int|null, unit?: string|null}>|null $material_requirements
 */
final class ProcedureDefinition extends AggregateRoot
{
    /** @use HasFactory<ProcedureDefinitionFactory> */
    use HasFactory;

    private const MAX_CODE_LENGTH = 100;

    private const MAX_NAME_LENGTH = 200;

    protected $table = 'clin_procedure_definitions';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id',
        'code', 'name', 'category', 'description', 'protocol_reference',
        'required_capability_code', 'requires_site', 'requires_laterality', 'requires_readiness_check',
        'material_requirements',
        'active', 'version',
    ];

    protected static function newFactory(): ProcedureDefinitionFactory
    {
        return ProcedureDefinitionFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requires_site' => 'boolean',
            'requires_laterality' => 'boolean',
            'requires_readiness_check' => 'boolean',
            'material_requirements' => 'array',
            'active' => 'boolean',
        ];
    }

    /**
     * Define a new catalog entry (ADR-146 D-C — the console / seeder write
     * path). `$id` is client-minted (a UUIDv7). `version` starts at 1.
     *
     * @param  list<array{material_ref: string, default_quantity?: float|int|null, unit?: string|null}>|null  $materialRequirements
     */
    public static function define(
        string $id,
        string $tenantId,
        string $code,
        string $name,
        ?string $category = null,
        ?string $description = null,
        ?string $protocolReference = null,
        ?string $requiredCapabilityCode = null,
        bool $requiresSite = false,
        bool $requiresLaterality = false,
        bool $requiresReadinessCheck = false,
        ?array $materialRequirements = null,
    ): self {
        $code = self::assertCode($code);
        $name = self::assertName($name);

        return new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'code' => $code,
            'name' => $name,
            'category' => self::blankToNull($category),
            'description' => self::blankToNull($description),
            'protocol_reference' => self::blankToNull($protocolReference),
            'required_capability_code' => self::blankToNull($requiredCapabilityCode),
            'requires_site' => $requiresSite,
            'requires_laterality' => $requiresLaterality,
            'requires_readiness_check' => $requiresReadinessCheck,
            'material_requirements' => $materialRequirements,
            'active' => true,
            'version' => 1,
        ]);
    }

    /**
     * Update this definition in place (ADR-146 D-C — the console write path
     * ONLY). Bumps `version`. `null` for an optional parameter leaves the
     * current value unchanged; pass an explicit value to change it.
     *
     * Named `revise()`, not `update()` — `update()` is Eloquent
     * `Model::update(array, array)` and cannot be overridden with an
     * incompatible signature.
     *
     * @param  list<array{material_ref: string, default_quantity?: float|int|null, unit?: string|null}>|null  $materialRequirements
     */
    public function revise(
        ?string $name = null,
        ?string $category = null,
        ?string $description = null,
        ?string $protocolReference = null,
        ?string $requiredCapabilityCode = null,
        ?bool $requiresSite = null,
        ?bool $requiresLaterality = null,
        ?bool $requiresReadinessCheck = null,
        ?array $materialRequirements = null,
        ?bool $active = null,
        bool $clearProtocolApproval = false,
    ): void {
        if ($clearProtocolApproval && $protocolReference !== null) {
            throw new InvalidArgumentException('A protocol approval cannot be set and cleared in the same revision.');
        }
        if ($name !== null) {
            $this->name = self::assertName($name);
        }
        if ($category !== null) {
            $this->category = self::blankToNull($category);
        }
        if ($description !== null) {
            $this->description = self::blankToNull($description);
        }
        if ($protocolReference !== null) {
            $this->protocol_reference = self::assertProtocolReference($protocolReference);
        }
        if ($clearProtocolApproval) {
            $this->protocol_reference = null;
        }
        if ($requiredCapabilityCode !== null) {
            $this->required_capability_code = self::blankToNull($requiredCapabilityCode);
        }
        if ($requiresSite !== null) {
            $this->requires_site = $requiresSite;
        }
        if ($requiresLaterality !== null) {
            $this->requires_laterality = $requiresLaterality;
        }
        if ($requiresReadinessCheck !== null) {
            $this->requires_readiness_check = $requiresReadinessCheck;
        }
        if ($materialRequirements !== null) {
            $this->material_requirements = $materialRequirements;
        }
        if ($active !== null) {
            $this->active = $active;
        }

        $this->version = (int) $this->version + 1;
    }

    private static function assertCode(string $code): string
    {
        $trimmed = trim($code);

        if ($trimmed === '') {
            throw new InvalidArgumentException('ProcedureDefinition::$code must not be blank (ADR-146 D-C).');
        }

        if (mb_strlen($trimmed) > self::MAX_CODE_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'ProcedureDefinition::$code must be at most %d characters (ADR-146 D-C).',
                self::MAX_CODE_LENGTH,
            ));
        }

        return $trimmed;
    }

    private static function assertName(string $name): string
    {
        $trimmed = trim($name);

        if ($trimmed === '') {
            throw new InvalidArgumentException('ProcedureDefinition::$name must not be blank (ADR-146 D-C).');
        }

        if (mb_strlen($trimmed) > self::MAX_NAME_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'ProcedureDefinition::$name must be at most %d characters (ADR-146 D-C).',
                self::MAX_NAME_LENGTH,
            ));
        }

        return $trimmed;
    }

    private static function assertProtocolReference(string $reference): string
    {
        $trimmed = trim($reference);
        if ($trimmed === '' || mb_strlen($trimmed) > 255) {
            throw new InvalidArgumentException('ProcedureDefinition::$protocolReference must contain 1 to 255 characters.');
        }

        return $trimmed;
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
