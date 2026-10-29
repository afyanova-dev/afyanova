<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\Model;

use App\Domains\Clinical\Contracts\Event\ClinicalPlanCreated;
use App\Domains\Clinical\Contracts\Event\ClinicalPlanUpdated;
use App\Domains\Clinical\Database\Factories\ClinicalPlanFactory;
use App\Domains\Clinical\Domain\Exception\IllegalClinicalPlanStatusTransition;
use App\Domains\Clinical\Domain\ValueObject\ClinicalPlanChangeType;
use App\Domains\Clinical\Domain\ValueObject\ClinicalPlanStatus;
use App\Domains\Clinical\Domain\ValueObject\ClinicalPlanType;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Encounter-originated longitudinal treatment / care plan for a patient
 * (12-clinical-domain.md §34–§39, §76; ADR-131, ADR-132, ADR-135, ADR-143).
 *
 * Partitioned aggregate: table `clin_plans` + append-only `clin_plan_history`.
 *
 * ORIGIN (ADR-143 D-C): `encounter_id` and `facility_id` are immutable originating
 * anchors from the encounter context (Invariant 12).
 *
 * LIFECYCLE (ADR-143 D-E): BOUNDED MUTATION + append-only history.
 * Status: ACTIVE | COMPLETED | DISCONTINUED.
 *
 * @property string $status ClinicalPlanStatus value (ACTIVE | COMPLETED | DISCONTINUED)
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $recorded_at
 */
final class ClinicalPlan extends AggregateRoot
{
    /** @use HasFactory<ClinicalPlanFactory> */
    use HasFactory;

    public const AUTHORED_CONTEXT = 'CLINICIAN';

    protected $table = 'clin_plans';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id', 'patient_id', 'encounter_id',
        'author_user_id', 'authored_context',
        'plan_type', 'clinical_intent', 'goals', 'planned_actions',
        'instructions', 'follow_up', 'status', 'closed_reason',
        'indication_diagnosis_id', 'indication_problem_id',
        'occurred_at', 'recorded_at',
    ];

    /** @var list<ClinicalPlanHistoryEntry> */
    private array $pendingHistory = [];

    protected static function newFactory(): ClinicalPlanFactory
    {
        return ClinicalPlanFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    public static function create(
        string $id,
        string $tenantId,
        string $facilityId,
        string $patientId,
        string $encounterId,
        string $authorUserId,
        string $planType,
        string $clinicalIntent,
        ?string $goals,
        ?string $plannedActions,
        ?string $instructions,
        ?string $followUp,
        ?string $indicationDiagnosisId,
        ?string $indicationProblemId,
        CarbonImmutable $occurredAt,
        CarbonImmutable $recordedAt,
    ): self {
        $clinicalIntent = self::assertClinicalIntent($clinicalIntent);
        $planType = ClinicalPlanType::assert($planType);

        $plan = new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'patient_id' => $patientId,
            'encounter_id' => $encounterId,
            'author_user_id' => $authorUserId,
            'authored_context' => self::AUTHORED_CONTEXT,
            'plan_type' => $planType,
            'clinical_intent' => $clinicalIntent,
            'goals' => $goals !== null && trim($goals) !== '' ? trim($goals) : null,
            'planned_actions' => $plannedActions !== null && trim($plannedActions) !== '' ? trim($plannedActions) : null,
            'instructions' => $instructions !== null && trim($instructions) !== '' ? trim($instructions) : null,
            'follow_up' => $followUp !== null && trim($followUp) !== '' ? trim($followUp) : null,
            'status' => ClinicalPlanStatus::ACTIVE,
            'closed_reason' => null,
            'indication_diagnosis_id' => $indicationDiagnosisId,
            'indication_problem_id' => $indicationProblemId,
            'occurred_at' => $occurredAt,
            'recorded_at' => $recordedAt,
        ]);

        $plan->recordThat(new ClinicalPlanCreated(
            eventId: (string) Str::uuid7(),
            tenantId: $tenantId,
            facilityId: $facilityId,
            patientId: $patientId,
            encounterId: $encounterId,
            clinicalPlanId: $id,
            authorUserId: $authorUserId,
            planType: $planType,
            occurredAt: (string) now()->toISOString(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));

        return $plan;
    }

    public function revise(
        string $changeType,
        string $planType,
        string $clinicalIntent,
        ?string $goals,
        ?string $plannedActions,
        ?string $instructions,
        ?string $followUp,
        ?string $indicationDiagnosisId,
        ?string $indicationProblemId,
        ?string $reason,
        string $authorUserId,
        CarbonImmutable $changeOccurredAt,
        CarbonImmutable $changeRecordedAt,
    ): void {
        $changeType = ClinicalPlanChangeType::assert($changeType);
        $planType = ClinicalPlanType::assert($planType);
        $clinicalIntent = self::assertClinicalIntent($clinicalIntent);

        if ($changeType === ClinicalPlanChangeType::CORRECTION && ($reason === null || trim($reason) === '')) {
            throw new InvalidArgumentException(
                'UpdateClinicalPlan: reason is required for CORRECTION (ADR-143 D-E).'
            );
        }

        $this->pendingHistory[] = ClinicalPlanHistoryEntry::fromPreChangeState(
            plan: $this,
            changeType: $changeType,
            reason: $reason !== null && trim($reason) !== '' ? trim($reason) : null,
            authorUserId: $authorUserId,
            occurredAt: $changeOccurredAt,
            recordedAt: $changeRecordedAt,
        );

        $this->plan_type = $planType;
        $this->clinical_intent = $clinicalIntent;
        $this->goals = $goals !== null && trim($goals) !== '' ? trim($goals) : null;
        $this->planned_actions = $plannedActions !== null && trim($plannedActions) !== '' ? trim($plannedActions) : null;
        $this->instructions = $instructions !== null && trim($instructions) !== '' ? trim($instructions) : null;
        $this->follow_up = $followUp !== null && trim($followUp) !== '' ? trim($followUp) : null;
        $this->indication_diagnosis_id = $indicationDiagnosisId;
        $this->indication_problem_id = $indicationProblemId;

        $this->recordThat(new ClinicalPlanUpdated(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            patientId: (string) $this->patient_id,
            encounterId: (string) $this->encounter_id,
            clinicalPlanId: (string) $this->id,
            authorUserId: $authorUserId,
            planType: $planType,
            changeType: $changeType,
            occurredAt: (string) now()->toISOString(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    public function close(string $outcome, ?string $reason): void
    {
        if ($this->status !== ClinicalPlanStatus::ACTIVE) {
            throw IllegalClinicalPlanStatusTransition::close((string) $this->status);
        }

        if ($outcome === ClinicalPlanStatus::DISCONTINUED) {
            if ($reason === null || trim($reason) === '') {
                throw new InvalidArgumentException(
                    'CloseClinicalPlan: reason is required when outcome is DISCONTINUED (ADR-143 D-E).'
                );
            }
            $this->status = ClinicalPlanStatus::DISCONTINUED;
            $this->closed_reason = trim($reason);
        } elseif ($outcome === ClinicalPlanStatus::COMPLETED) {
            if ($reason !== null && trim($reason) !== '') {
                throw new InvalidArgumentException(
                    'CloseClinicalPlan: reason is forbidden when outcome is COMPLETED (ADR-143 D-E).'
                );
            }
            $this->status = ClinicalPlanStatus::COMPLETED;
            $this->closed_reason = null;
        } else {
            throw new InvalidArgumentException(sprintf(
                'CloseClinicalPlan outcome must be COMPLETED or DISCONTINUED, got [%s] (ADR-143 D-E).',
                $outcome,
            ));
        }
    }

    /**
     * @return list<ClinicalPlanHistoryEntry>
     */
    public function releasePendingHistory(): array
    {
        $history = $this->pendingHistory;
        $this->pendingHistory = [];

        return $history;
    }

    public function isActive(): bool
    {
        return $this->status === ClinicalPlanStatus::ACTIVE;
    }

    public function isCompleted(): bool
    {
        return $this->status === ClinicalPlanStatus::COMPLETED;
    }

    public function isDiscontinued(): bool
    {
        return $this->status === ClinicalPlanStatus::DISCONTINUED;
    }

    private static function assertClinicalIntent(string $clinicalIntent): string
    {
        $trimmed = trim($clinicalIntent);
        if ($trimmed === '') {
            throw new InvalidArgumentException(
                'ClinicalPlan::$clinical_intent must not be blank (ADR-143 D-D).'
            );
        }

        return $trimmed;
    }
}
