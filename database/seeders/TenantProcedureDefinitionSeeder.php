<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Clinical\Domain\Model\ProcedureDefinition;
use App\Domains\Clinical\Domain\ProcedureDefinitionRepository;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Str;

/**
 * PHASE 6 (increment CP.1) — a starter procedure catalog for one tenant
 * (13-clinical-procedures-domain.md §13; ADR-146 D-C).
 *
 * Unlike `GlobalReferenceDataSeeder` (global, tenant-less reference data),
 * `clin_procedure_definitions` is TENANT-SCOPED — this seeder is invoked PER
 * TENANT (e.g. during tenant provisioning, or from a test fixture), not from
 * `DatabaseSeeder::run()`. Idempotent, keyed on `code` — a repeat run for the
 * same tenant updates nothing (an existing code is left untouched; use
 * `clinical:define-procedure update` to change one).
 *
 * Covers the 13 §13 categories with one representative each. NOT exhaustive —
 * a facility's clinical governance extends the catalog via
 * `clinical:define-procedure` (ADR-146 D-C).
 */
final class TenantProcedureDefinitionSeeder
{
    public static function seed(string $tenantId): void
    {
        TenantContextScope::run($tenantId, null, function () use ($tenantId): void {
            $definitions = app(ProcedureDefinitionRepository::class);

            foreach (self::starterSet() as $row) {
                if ($definitions->findByCode($tenantId, $row['code']) !== null) {
                    continue; // idempotent — leave an existing code untouched
                }

                $definitions->save(ProcedureDefinition::define(
                    id: (string) Str::uuid7(),
                    tenantId: $tenantId,
                    code: $row['code'],
                    name: $row['name'],
                    category: $row['category'],
                    description: $row['description'] ?? null,
                    requiredCapabilityCode: $row['capability'] ?? null,
                    requiresSite: $row['requires_site'] ?? false,
                    requiresLaterality: $row['requires_laterality'] ?? false,
                    requiresReadinessCheck: $row['requires_readiness_check'] ?? false,
                ));
            }
        });
    }

    /**
     * @return list<array{code: string, name: string, category: string, description?: string, capability?: string, requires_site?: bool, requires_laterality?: bool, requires_readiness_check?: bool}>
     */
    private static function starterSet(): array
    {
        return [
            ['code' => 'INJECTION', 'name' => 'Injection', 'category' => 'INJECTION', 'description' => 'Administration of an injectable medication.', 'capability' => 'OUTPATIENT_CARE', 'requires_site' => true, 'requires_laterality' => true],
            ['code' => 'DRESSING', 'name' => 'Wound Dressing', 'category' => 'DRESSING_WOUND_CARE', 'description' => 'Routine wound dressing change.', 'capability' => 'OUTPATIENT_CARE', 'requires_site' => true],
            ['code' => 'MINOR_PROCEDURE', 'name' => 'Minor Procedure', 'category' => 'MINOR_PROCEDURE', 'description' => 'A minor clinical procedure (e.g. incision and drainage).', 'capability' => 'OUTPATIENT_CARE', 'requires_site' => true, 'requires_laterality' => true, 'requires_readiness_check' => true],
            ['code' => 'RESPIRATORY_PROCEDURE', 'name' => 'Nebulization', 'category' => 'RESPIRATORY_PROCEDURE', 'description' => 'Nebulized medication administration.', 'capability' => 'OUTPATIENT_CARE'],
            ['code' => 'CATHETERIZATION', 'name' => 'Urinary Catheterization', 'category' => 'CATHETERIZATION', 'description' => 'Insertion of a urinary catheter.', 'capability' => 'OUTPATIENT_CARE', 'requires_readiness_check' => true],
            ['code' => 'SUTURING', 'name' => 'Suturing', 'category' => 'SUTURING', 'description' => 'Wound closure by suture.', 'capability' => 'OUTPATIENT_CARE', 'requires_site' => true, 'requires_laterality' => true, 'requires_readiness_check' => true],
            ['code' => 'SPECIMEN_COLLECTION', 'name' => 'Specimen Collection', 'category' => 'SPECIMEN_COLLECTION', 'description' => 'Collection of a clinical specimen for testing.', 'capability' => 'OUTPATIENT_CARE'],
            ['code' => 'NURSING_PROCEDURE', 'name' => 'Nursing Procedure', 'category' => 'NURSING_PROCEDURE', 'description' => 'A routine nursing procedure (13 §18).', 'capability' => 'OUTPATIENT_CARE'],
        ];
    }
}
