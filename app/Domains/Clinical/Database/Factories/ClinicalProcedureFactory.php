<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Database\Factories;

use App\Domains\Clinical\Domain\Enum\ClinicalProcedureStatus;
use App\Domains\Clinical\Domain\Model\ClinicalProcedure;
use App\Domains\Clinical\Domain\Model\ProcedureDefinition;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ClinicalProcedure>
 */
class ClinicalProcedureFactory extends Factory
{
    protected $model = ClinicalProcedure::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'facility_id' => (string) Str::uuid7(),
            'patient_id' => (string) Str::uuid7(),
            'encounter_id' => (string) Str::uuid7(),
            'author_user_id' => (string) Str::uuid7(),
            'authored_context' => ClinicalProcedure::AUTHORED_CONTEXT_CLINICIAN,
            'procedure_number' => 'PROC-'.fake()->unique()->numerify('########'),
            'status' => ClinicalProcedureStatus::Requested,
            'definition_id' => ProcedureDefinition::factory(),
            'definition_version' => 1,
            'procedure_category' => 'DRESSING_WOUND_CARE',
            'requested_by_user_id' => (string) Str::uuid7(),
            'authorized_by_user_id' => null,
            'performed_by_user_id' => null,
            'location_label' => null,
            'site' => null,
            'laterality' => null,
            'outcome' => null,
            'outcome_detail' => null,
            'complications' => null,
            'status_reason' => null,
            'requested_at' => now(),
            'authorized_at' => null,
            'ready_at' => null,
            'started_at' => null,
            'completed_at' => null,
            'source_diagnosis_id' => null,
            'source_clinical_plan_id' => null,
            'indication' => null,
            'clinical_note_id' => null,
            'financial_clearance_ref' => null,
            'financial_policy_version' => null,
            'occurred_at' => now(),
            'recorded_at' => now(),
        ];
    }

    public function forTenant(string $tenantId): static
    {
        // The default `definition_id` relationship (ProcedureDefinition::factory())
        // otherwise mints its OWN unrelated tenant, which would violate
        // clin_procedure_definitions' RLS WITH CHECK once `app.tenant_id` is set
        // to $tenantId (IC §11.2) — pin the definition to the SAME tenant.
        return $this->state(['tenant_id' => $tenantId, 'definition_id' => ProcedureDefinition::factory()->forTenant($tenantId)]);
    }

    public function forPatient(string $patientId): static
    {
        return $this->state(['patient_id' => $patientId]);
    }

    public function forEncounter(string $encounterId): static
    {
        return $this->state(['encounter_id' => $encounterId]);
    }

    public function atFacility(string $facilityId): static
    {
        return $this->state(['facility_id' => $facilityId]);
    }

    public function authoredBy(string $userId): static
    {
        return $this->state(['author_user_id' => $userId]);
    }

    public function forDefinition(string $definitionId, int $definitionVersion = 1): static
    {
        return $this->state(['definition_id' => $definitionId, 'definition_version' => $definitionVersion]);
    }

    public function withStatus(ClinicalProcedureStatus $status): static
    {
        return $this->state(['status' => $status]);
    }

    /** A nursing-authored procedure (13 §18). */
    public function byNurse(): static
    {
        return $this->state(['authored_context' => ClinicalProcedure::AUTHORED_CONTEXT_NURSING]);
    }
}
