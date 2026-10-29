<?php

declare(strict_types=1);

namespace App\Domains\Patient\Domain\Model;

use App\Domains\Patient\Contracts\Event\PatientRelationshipRecorded;
use App\Domains\Patient\Contracts\Event\PatientRelationshipRemoved;
use App\Domains\Patient\Database\Factories\PatientRelationshipFactory;
use App\Domains\Patient\Domain\Enum\RelationshipRole;
use App\Domains\Patient\Domain\Enum\RelationshipStatus;
use App\Domains\Patient\Domain\Exception\RelationshipAlreadyInactive;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * A person related to a patient — emergency contact / next of kin / guardian /
 * parent / … (10-patient-domain.md §35-§38, §152; ADR-126).
 *
 * Its OWN small aggregate (ADR-126 D-1), same reasoning as PatientIdentifier
 * and PatientRegistration: recorded and removed independently of registration,
 * on its own transaction boundary (IC §4.1), never loaded through the Patient
 * aggregate root (§154 scopes that boundary to Identity / Identifiers / Core
 * Demographics / Status). `patient_id` is a plain indexed uuid — no database
 * foreign key (the shipped precedent); the tenant-correct guard is the handler
 * loading the patient through the RLS-scoped PatientRepository first.
 *
 * The related party is FREE-FORM (name / label / phone / note) — no link to
 * another patient row in PHASE 5 (ADR-126 D-7; §38 "not replacement patient
 * identities").
 *
 * Larastan reads the `$casts` property, not `casts()`, so the effective types
 * are pinned in `@property` tags for callers outside this class.
 *
 * @domain      Patient
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @facility    not applicable — identity-level, tenant-scoped like pat_patients
 *              (ADR-114 / ADR-126).
 *
 * @property RelationshipRole $role
 * @property RelationshipStatus $status
 * @property CarbonImmutable|null $removed_at
 */
final class PatientRelationship extends AggregateRoot
{
    /** @use HasFactory<PatientRelationshipFactory> */
    use HasFactory;

    protected $table = 'pat_patient_relationships';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'patient_id',
        'role', 'related_name', 'relationship_label', 'related_phone', 'note',
        'status', 'recorded_by_user_id', 'removed_by_user_id', 'removed_at',
    ];

    protected static function newFactory(): PatientRelationshipFactory
    {
        return PatientRelationshipFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => RelationshipRole::class,
            'status' => RelationshipStatus::class,
            'removed_at' => 'immutable_datetime',
        ];
    }

    /**
     * Record a new relationship (10-patient-domain.md §35-§38; ADR-126). The
     * caller (RecordPatientRelationshipHandler) has already verified the
     * patient exists in the current tenant.
     */
    public static function record(
        string $id,
        string $tenantId,
        string $patientId,
        RelationshipRole $role,
        string $relatedName,
        ?string $relationshipLabel,
        ?string $relatedPhone,
        ?string $note,
        string $recordedByUserId,
    ): self {
        $relationship = new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'patient_id' => $patientId,
            'role' => $role,
            'related_name' => $relatedName,
            'relationship_label' => $relationshipLabel,
            'related_phone' => $relatedPhone,
            'note' => $note,
            'status' => RelationshipStatus::Active,
            'recorded_by_user_id' => $recordedByUserId,
        ]);

        $relationship->recordThat(new PatientRelationshipRecorded(
            eventId: (string) Str::uuid7(),
            tenantId: $tenantId,
            patientId: $patientId,
            relationshipId: $id,
            role: $role->value,
            occurredAt: (string) now()->toISOString(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));

        return $relationship;
    }

    /**
     * ACTIVE -> INACTIVE (ADR-126 D-8). Deactivation, not deletion — the row
     * and its audit trail are kept.
     */
    public function deactivate(string $removedByUserId): void
    {
        if ($this->status !== RelationshipStatus::Active) {
            throw RelationshipAlreadyInactive::forId((string) $this->id);
        }

        $this->status = RelationshipStatus::Inactive;
        $this->removed_by_user_id = $removedByUserId;
        $this->removed_at = CarbonImmutable::now();

        $this->recordThat(new PatientRelationshipRemoved(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            patientId: (string) $this->patient_id,
            relationshipId: (string) $this->id,
            role: $this->role->value,
            occurredAt: (string) now()->toISOString(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }
}
