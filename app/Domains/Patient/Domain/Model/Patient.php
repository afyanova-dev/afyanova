<?php

declare(strict_types=1);

namespace App\Domains\Patient\Domain\Model;

use App\Domains\Patient\Contracts\Event\PatientDeactivated;
use App\Domains\Patient\Contracts\Event\PatientMarkedDeceased;
use App\Domains\Patient\Contracts\Event\PatientMerged;
use App\Domains\Patient\Contracts\Event\PatientReactivated;
use App\Domains\Patient\Contracts\Event\PatientRegistered;
use App\Domains\Patient\Contracts\Event\PatientUnmerged;
use App\Domains\Patient\Contracts\Event\PatientUpdated;
use App\Domains\Patient\Database\Factories\PatientFactory;
use App\Domains\Patient\Domain\Enum\DobPrecision;
use App\Domains\Patient\Domain\Enum\PatientStatus;
use App\Domains\Patient\Domain\Enum\Sex;
use App\Domains\Patient\Domain\Exception\IllegalPatientStatusTransition;
use App\Domains\Patient\Domain\Exception\MergedPatientIsImmutable;
use App\Domains\Patient\Domain\Exception\PatientMergeRejected;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * The tenant-wide Master Patient Index identity (10-patient-domain.md;
 * ADR-114 — Patient Identity Is a Tenant-Wide Master Patient Index).
 *
 * `casts()` below (a method, not the static `$casts` property) is how the
 * enum/date casts are layered on top of AggregateRoot's own `$casts` property
 * (IC §4.1). Larastan infers property types from the `$casts` property, not
 * from a dynamic method, so the `@property` tags document the effective
 * shape explicitly for callers outside this class (e.g.
 * Infrastructure/Reader/EloquentPatientReader).
 *
 * @domain      Patient
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @facility    not applicable — pat_patients is tenant-scoped only (ADR-114);
 *              facility participation is the separate PatientRegistration
 *              aggregate (Slice 2), never a column here.
 *
 * @property Sex $sex
 * @property DobPrecision $dob_precision
 * @property PatientStatus $status
 * @property CarbonImmutable|null $date_of_birth
 */
final class Patient extends AggregateRoot
{
    /** @use HasFactory<PatientFactory> */
    use HasFactory;

    protected $table = 'pat_patients';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'patient_number',
        'given_name', 'family_name', 'middle_name', 'display_name',
        'sex', 'date_of_birth', 'dob_precision', 'primary_phone', 'email',
        'status', 'is_provisional',
        'photo_document_id', 'photo_captured_at', 'photo_captured_by',
        'registration_source', 'registered_by_user_id',
    ];

    protected static function newFactory(): PatientFactory
    {
        return PatientFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sex' => Sex::class,
            'dob_precision' => DobPrecision::class,
            'status' => PatientStatus::class,
            'date_of_birth' => 'immutable_date',
            'is_provisional' => 'boolean',
            'photo_captured_at' => 'immutable_datetime',
        ];
    }

    /**
     * Register a new patient identity (10-patient-domain.md §15; ADR-114,
     * ADR-116, ADR-119). `$patientNumber` is pre-allocated by the caller via
     * the tenant-scoped counter (ADR-115) — the aggregate does not allocate
     * its own identifier. `$facilityId` is the registering facility for the
     * event only; it is never stored on this aggregate (ADR-114).
     *
     * PHASE 5 duplicate detection (ADR-117) is not yet wired at this slice —
     * every call registers unconditionally. That gate is a later slice.
     */
    public static function register(
        string $id,
        string $tenantId,
        string $patientNumber,
        string $facilityId,
        string $givenName,
        string $familyName,
        ?string $middleName,
        string $displayName,
        Sex $sex,
        ?string $dateOfBirth,
        DobPrecision $dobPrecision,
        ?string $primaryPhone,
        ?string $email,
        bool $provisional,
        ?string $source,
        string $registeredByUserId,
        ?string $photoDocumentId = null,
        ?string $photoCapturedBy = null,
    ): self {
        $patient = new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'patient_number' => $patientNumber,
            'given_name' => $givenName,
            'family_name' => $familyName,
            'middle_name' => $middleName,
            'display_name' => $displayName,
            'sex' => $sex,
            'date_of_birth' => $dateOfBirth,
            'dob_precision' => $dobPrecision,
            'primary_phone' => $primaryPhone,
            'email' => $email,
            'status' => PatientStatus::Active,
            'is_provisional' => $provisional,
            'photo_document_id' => $photoDocumentId,
            'photo_captured_at' => $photoDocumentId !== null ? now() : null,
            'photo_captured_by' => $photoCapturedBy,
            'registration_source' => $source,
            'registered_by_user_id' => $registeredByUserId,
        ]);

        $patient->recordThat(new PatientRegistered(
            eventId: (string) Str::uuid7(),
            tenantId: $tenantId,
            patientId: $id,
            patientNumber: $patientNumber,
            facilityId: $facilityId,
            isProvisional: $provisional,
            occurredAt: (string) now()->toISOString(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));

        return $patient;
    }

    /**
     * ACTIVE -> INACTIVE (ADR-116). Deliberate deactivation; reversible.
     */
    public function markInactive(): void
    {
        $this->transitionStatusTo(PatientStatus::Inactive, from: [PatientStatus::Active]);

        $this->recordThat(new PatientDeactivated(...$this->lifecycleEventFields()));
    }

    /**
     * INACTIVE -> ACTIVE (ADR-116). MERGED and DECEASED never reach ACTIVE this
     * way (MERGED only via the unmerge process; DECEASED is terminal).
     */
    public function reactivate(): void
    {
        $this->transitionStatusTo(PatientStatus::Active, from: [PatientStatus::Inactive]);

        $this->recordThat(new PatientReactivated(...$this->lifecycleEventFields()));
    }

    /**
     * {ACTIVE, INACTIVE} -> DECEASED (ADR-116). Terminal in PHASE 5 — a
     * deceased-in-error correction is a governed process, not an ordinary
     * transition. Identity-level state only; the clinical record of the death
     * is Clinical's (§45).
     */
    public function markDeceased(): void
    {
        $this->transitionStatusTo(PatientStatus::Deceased, from: [PatientStatus::Active, PatientStatus::Inactive]);

        $this->recordThat(new PatientMarkedDeceased(...$this->lifecycleEventFields()));
    }

    /**
     * The non-surviving side of a merge: {ACTIVE, INACTIVE} -> MERGED
     * (10-patient-domain.md §21-§24; ADR-116, ADR-118, ADR-128). Identity-layer
     * only — no child records are touched. The caller (MergePatientsHandler)
     * has locked both rows and validated the surviving patient.
     */
    public function merge(string $survivingPatientId, string $mergeId): void
    {
        if ($this->status !== PatientStatus::Active && $this->status !== PatientStatus::Inactive) {
            throw PatientMergeRejected::nonSurvivorNotEligible($this->status);
        }

        $this->status = PatientStatus::Merged;

        $this->recordThat(new PatientMerged(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            survivingPatientId: $survivingPatientId,
            nonSurvivingPatientId: (string) $this->id,
            mergeId: $mergeId,
            occurredAt: (string) now()->toISOString(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    /**
     * Reverse the identity-layer merge: MERGED -> the exact pre-merge status
     * captured on the identity-history row (ADR-116, ADR-128 M-3).
     */
    public function restoreFromMerge(PatientStatus $priorStatus, string $survivingPatientId, string $mergeId): void
    {
        if ($this->status !== PatientStatus::Merged) {
            throw PatientMergeRejected::notCurrentlyMerged((string) $this->id);
        }

        $this->status = $priorStatus;

        $this->recordThat(new PatientUnmerged(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            survivingPatientId: $survivingPatientId,
            nonSurvivingPatientId: (string) $this->id,
            mergeId: $mergeId,
            occurredAt: (string) now()->toISOString(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    /**
     * Invariant 14 (ADR-128 M-9) — a MERGED identity cannot independently gain
     * or change records. Every non-lifecycle Patient write path (UpdatePatient,
     * identifiers, contacts, addresses, relationships, facility registration)
     * calls this after loading the patient. `MergePatients` (transition TO
     * MERGED) and `RequestUnmerge` (operation ON a MERGED row) are the
     * intentional exceptions and do not call it.
     */
    public function assertMutable(): void
    {
        if ($this->status === PatientStatus::Merged) {
            throw MergedPatientIsImmutable::for((string) $this->id);
        }
    }

    /**
     * Apply a partial demographic patch (10-patient-domain.md §26, §60;
     * ADR-127). Only keys present in `$changes` are touched; an explicit `null`
     * clears a nullable field. `display_name` is re-derived from the post-patch
     * name parts when a name part changed and `display_name` was not itself in
     * the patch (ADR-127 rule 9). `is_provisional` auto-clears when the
     * four-field completeness rule is met (ADR-119 / ADR-127 rule 1) — one-way.
     * `PatientUpdated` is recorded only when something actually changed.
     *
     * @param  array<string, string|null>  $changes  the validated UpdatePatient patch
     * @return array{changedFields: list<string>, previous: array<string, string|null>, new: array<string, string|null>, provisionalCleared: bool}
     */
    public function reviseDemographics(array $changes): array
    {
        $before = $this->demographicSnapshot();

        foreach ($changes as $field => $value) {
            $this->setAttribute($field, $value);
        }

        // Re-derive display_name only when a name PART actually changed (not merely
        // present in the patch) and display_name was not itself patched — ADR-127 rule 9.
        $nameChanged = (string) $this->given_name !== $before['given_name']
            || (string) $this->family_name !== $before['family_name'];
        if ($nameChanged && ! array_key_exists('display_name', $changes)) {
            $this->display_name = trim("{$this->given_name} {$this->family_name}");
        }

        $after = $this->demographicSnapshot();

        /** @var list<string> $changedFields */
        $changedFields = array_values(array_filter(
            self::EDITABLE_DEMOGRAPHICS,
            static fn (string $field): bool => $before[$field] !== $after[$field],
        ));

        $provisionalCleared = false;
        if ($this->is_provisional && $this->meetsCompleteness()) {
            $this->is_provisional = false;
            $provisionalCleared = true;
        }

        if ($changedFields === [] && ! $provisionalCleared) {
            return ['changedFields' => [], 'previous' => [], 'new' => [], 'provisionalCleared' => false];
        }

        $this->recordThat(new PatientUpdated(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            patientId: (string) $this->id,
            changedFields: $changedFields,
            provisionalCleared: $provisionalCleared,
            occurredAt: (string) now()->toISOString(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));

        return [
            'changedFields' => $changedFields,
            'previous' => array_intersect_key($before, array_flip($changedFields)),
            'new' => array_intersect_key($after, array_flip($changedFields)),
            'provisionalCleared' => $provisionalCleared,
        ];
    }

    /** ADR-127 rule 1 — the PHASE 5 minimum-completeness rule for `is_provisional`. */
    private function meetsCompleteness(): bool
    {
        return trim((string) $this->given_name) !== ''
            && trim((string) $this->family_name) !== ''
            && $this->sex !== Sex::Unknown
            && $this->date_of_birth !== null;
    }

    /** The nine patchable demographic columns (mirrors UpdatePatient::EDITABLE). @var list<string> */
    private const EDITABLE_DEMOGRAPHICS = [
        'given_name', 'family_name', 'middle_name', 'display_name',
        'date_of_birth', 'dob_precision', 'sex', 'primary_phone', 'email',
    ];

    /**
     * String|null view of the demographic columns, for change detection and the
     * audit before/after (enums -> backing value, date -> Y-m-d).
     *
     * @return array<string, string|null>
     */
    private function demographicSnapshot(): array
    {
        $snapshot = [];
        foreach (self::EDITABLE_DEMOGRAPHICS as $field) {
            $value = $this->getAttribute($field);
            $snapshot[$field] = match (true) {
                $value === null => null,
                $value instanceof \BackedEnum => (string) $value->value,
                $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
                default => (string) $value,
            };
        }

        return $snapshot;
    }

    /**
     * @param  non-empty-list<PatientStatus>  $from
     */
    private function transitionStatusTo(PatientStatus $to, array $from): void
    {
        if (! in_array($this->status, $from, true)) {
            throw IllegalPatientStatusTransition::from($this->status, $to);
        }

        $this->status = $to;
    }

    /**
     * The identity + temporal envelope shared by every PHI-free lifecycle event.
     *
     * @return array{eventId: string, tenantId: string, patientId: string, occurredAt: string, correlationId: string, causationId: ?string}
     */
    private function lifecycleEventFields(): array
    {
        return [
            'eventId' => (string) Str::uuid7(),
            'tenantId' => (string) $this->tenant_id,
            'patientId' => (string) $this->id,
            'occurredAt' => (string) now()->toISOString(),
            'correlationId' => CorrelationId::get(),
            'causationId' => Causation::get(),
        ];
    }
}
