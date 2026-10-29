<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Domain\Model;

use App\Domains\Encounter\Contracts\Event\EncounterAbandoned;
use App\Domains\Encounter\Contracts\Event\EncounterAdmitted;
use App\Domains\Encounter\Contracts\Event\EncounterArrived;
use App\Domains\Encounter\Contracts\Event\EncounterCancelled;
use App\Domains\Encounter\Contracts\Event\EncounterCompleted;
use App\Domains\Encounter\Contracts\Event\EncounterCreated;
use App\Domains\Encounter\Contracts\Event\EncounterDispositionChanged;
use App\Domains\Encounter\Contracts\Event\EncounterLocationChanged;
use App\Domains\Encounter\Contracts\Event\EncounterReopened;
use App\Domains\Encounter\Contracts\Event\EncounterStarted;
use App\Domains\Encounter\Contracts\Event\EncounterTransferred;
use App\Domains\Encounter\Database\Factories\EncounterFactory;
use App\Domains\Encounter\Domain\Enum\EncounterDisposition;
use App\Domains\Encounter\Domain\Enum\EncounterStatus;
use App\Domains\Encounter\Domain\Enum\EncounterType;
use App\Domains\Encounter\Domain\Exception\IllegalEncounterTransition;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * One patient attendance at one facility — the visit (11-encounter-domain.md;
 * ADR-101, ADR-067). A container of references, not of records: it holds the
 * shared temporal / operational / facility context every downstream domain
 * needs and the `encounter_id` they reference; clinical, procedural,
 * diagnostic, medication, surgical and financial truth stay in their own
 * domains (§177, §178, ADR-130).
 *
 * `casts()` (the method, not the `$casts` property) layers the enum / date
 * casts over AggregateRoot's own `$casts`; Larastan reads the property, so the
 * effective shape is pinned in `@property` tags for callers outside this class.
 *
 * @domain      Encounter
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @facility    required — an encounter happens at exactly one facility (ADR-101,
 *              Invariant 3); `tenant_id` + `facility_id` are both NOT NULL and
 *              come from TenantContext, never client input (Invariant 12,
 *              ADR-037).
 *
 * @property EncounterType $encounter_type
 * @property EncounterStatus $status
 * @property EncounterDisposition|null $disposition
 * @property CarbonImmutable|null $arrived_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $admitted_at
 * @property CarbonImmutable|null $completed_at
 */
final class Encounter extends AggregateRoot
{
    /** @use HasFactory<EncounterFactory> */
    use HasFactory;

    protected $table = 'enc_encounters';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id', 'patient_id',
        'encounter_number', 'encounter_type', 'status', 'disposition',
        'department_id', 'unit_id',
        'arrived_at', 'started_at', 'admitted_at', 'completed_at',
        'created_by_user_id', 'created_source',
    ];

    protected static function newFactory(): EncounterFactory
    {
        return EncounterFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'encounter_type' => EncounterType::class,
            'status' => EncounterStatus::class,
            'disposition' => EncounterDisposition::class,
            'arrived_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'admitted_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /**
     * Open a new encounter — the authoritative check-in (11-encounter-domain.md
     * §8; ADR-104, ADR-121, ADR-130). `$encounterNumber` is pre-allocated by the
     * caller via the tenant counter (ADR-115). `$status` is resolved by the
     * EncounterLifecycle domain service — `ARRIVED` for a walk-in (the PHASE 5
     * default), `PLANNED` only on an explicit planned intent. `$id` is
     * client-minted (a UUIDv7) so the Auditable command can name its target
     * before the row exists.
     *
     * The patient's tenant-MPI existence + ACTIVE lifecycle status and the
     * facility capability check are the handler's responsibility (via
     * `PatientApi` / `FacilityCapabilities`) — this factory assumes they passed.
     */
    public static function open(
        string $id,
        string $tenantId,
        string $facilityId,
        string $patientId,
        string $encounterNumber,
        EncounterType $type,
        EncounterStatus $status,
        ?string $departmentId,
        ?string $unitId,
        string $createdByUserId,
        string $source,
    ): self {
        $encounter = new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'patient_id' => $patientId,
            'encounter_number' => $encounterNumber,
            'encounter_type' => $type,
            'status' => $status,
            'disposition' => null,
            'department_id' => $departmentId,
            'unit_id' => $unitId,
            'arrived_at' => $status === EncounterStatus::Arrived ? CarbonImmutable::now() : null,
            'started_at' => null,
            'admitted_at' => null,
            'completed_at' => null,
            'created_by_user_id' => $createdByUserId,
            'created_source' => $source,
        ]);

        $encounter->recordThat(new EncounterCreated(
            eventId: (string) Str::uuid7(),
            tenantId: $tenantId,
            facilityId: $facilityId,
            encounterId: $id,
            patientId: $patientId,
            encounterNumber: $encounterNumber,
            encounterType: $type->value,
            status: $status->value,
            occurredAt: (string) now()->toISOString(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));

        return $encounter;
    }

    /**
     * `PLANNED → ARRIVED` (11-encounter-domain.md §19; ADR-121) — the patient is
     * physically present against this pre-created encounter. The
     * `PLANNED → ARRIVED` legality is validated by EncounterLifecycle before
     * this is called (ADR-121 — no command writes status directly).
     *
     * Changes ONLY `status` and `arrived_at` (ADR-130 C — a transition
     * timestamp, set once, from server time, never a caller value). Moving to
     * `ARRIVED` also brings the row into the `enc_one_active_per_patient_facility`
     * set (ADR-121); the handler translates a unique-index violation on save
     * into `ActiveEncounterExists`.
     */
    public function recordArrival(): void
    {
        $this->status = EncounterStatus::Arrived;
        $this->arrived_at = CarbonImmutable::now();

        $this->recordThat(new EncounterArrived(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            encounterId: (string) $this->id,
            patientId: (string) $this->patient_id,
            occurredAt: (string) now()->toISOString(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    /**
     * `ARRIVED → ACTIVE` (11-encounter-domain.md §19, §31; ADR-121) — care has
     * begun on this attendance. The `ARRIVED → ACTIVE` legality is validated by
     * EncounterLifecycle before this is called (ADR-121 — no command writes
     * status directly).
     *
     * Changes ONLY `status` and `started_at` (ADR-130 C — a transition
     * timestamp, set once, from server time, never a caller value). `arrived_at`
     * and every other field are left untouched. Both `ARRIVED` and `ACTIVE` are
     * inside the `enc_one_active_per_patient_facility` predicate with an
     * unchanged key, so this transition cannot violate that index.
     */
    public function start(): void
    {
        $this->status = EncounterStatus::Active;
        $this->started_at = CarbonImmutable::now();

        $this->recordThat(new EncounterStarted(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            encounterId: (string) $this->id,
            patientId: (string) $this->patient_id,
            occurredAt: (string) now()->toISOString(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    /**
     * Admit an active OUTPATIENT / EMERGENCY encounter as an inpatient — the one
     * sanctioned in-place `encounter_type` conversion (11-encounter-domain.md
     * §14.1, §78; ADR-108, ADR-121, ADR-130). Legality (status `ACTIVE`, type
     * `OUTPATIENT`/`EMERGENCY`) is validated by EncounterLifecycle and the
     * `INPATIENT_CARE` capability by the handler before this is called.
     *
     * Changes ONLY `encounter_type` (→ `INPATIENT`), `admitted_at`
     * (→ server `now()`, set once — ADR-130 C, Invariant 16) and `disposition`
     * (→ `ADMITTED`, the pre-admission disposition — ADR-130 A, §14.1). `status`
     * stays `ACTIVE` (ADR-121 — "status unchanged"); `arrived_at` / `started_at`
     * and every other field are left untouched. `status` staying `ACTIVE` and
     * the unchanged index key mean the `enc_one_active_per_patient_facility`
     * index cannot be violated. Bed / ward assignment is Bed & Ward
     * Management's (ADR-107, §14.2).
     */
    public function admit(): void
    {
        $this->encounter_type = EncounterType::Inpatient;
        $this->admitted_at = CarbonImmutable::now();
        $this->disposition = EncounterDisposition::Admitted;

        $this->recordThat(new EncounterAdmitted(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            encounterId: (string) $this->id,
            patientId: (string) $this->patient_id,
            occurredAt: (string) now()->toISOString(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    /**
     * `ChangeEncounterDisposition` (11-encounter-domain.md §12, §76, §77, §79;
     * ADR-121: `ACTIVE → ACTIVE`; ADR-130 A) — record or revise what happened
     * (or will happen) to the patient at the end of the attendance. Status
     * legality and the settable-value rule are validated by EncounterLifecycle
     * before this is called (ADR-121 — no command writes status directly).
     *
     * Changes ONLY `disposition`. `status` stays `ACTIVE`; `encounter_type`,
     * `arrived_at`, `started_at`, `admitted_at`, `completed_at`, `department_id`,
     * `unit_id` and every identity field are left untouched. There is no
     * `disposition_changed_at` column (ADR-130 C/E — the change time is on the
     * event and the audit row). A same-value request is rejected here — the
     * command is an explicit change, not an idempotent no-op (Invariant 15).
     */
    public function changeDisposition(EncounterDisposition $disposition): void
    {
        if ($this->disposition === $disposition) {
            throw IllegalEncounterTransition::dispositionUnchanged($disposition);
        }

        $this->disposition = $disposition;

        $this->recordThat(new EncounterDispositionChanged(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            encounterId: (string) $this->id,
            patientId: (string) $this->patient_id,
            disposition: $disposition->value,
            occurredAt: (string) now()->toISOString(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    /**
     * `AssignEncounterLocation` (11-encounter-domain.md §36, §37, §38, §131,
     * §163; ADR-121: `ARRIVED or ACTIVE → unchanged`; ADR-130 B) — set / change
     * the coarse care-context location. Status legality is validated by
     * EncounterLifecycle before this is called.
     *
     * Patch semantics (ratified D-2): a non-null argument sets that field, `null`
     * leaves it unchanged. A resolved no-op (the pair is already the current
     * location) is rejected — the command is an explicit change, not an
     * idempotent no-op (Invariant 15). Changes ONLY `department_id` /
     * `unit_id`. `status` stays unchanged; `encounter_type`, `disposition`,
     * `arrived_at` / `started_at` / `admitted_at` / `completed_at` and every
     * identity field are left untouched. There is no `location_changed_at`
     * column (ADR-130 C/E — the time is on the event and the audit row). No
     * `room_id` / `bed_id` / movement history — that is Bed & Ward Management's /
     * Queueing & Patient Flow's (ADR-107; doc 10 §6).
     */
    public function assignLocation(?string $departmentId, ?string $unitId): void
    {
        $newDepartmentId = $departmentId ?? $this->department_id;
        $newUnitId = $unitId ?? $this->unit_id;

        if ($newDepartmentId === $this->department_id && $newUnitId === $this->unit_id) {
            throw IllegalEncounterTransition::locationUnchanged();
        }

        $this->department_id = $newDepartmentId;
        $this->unit_id = $newUnitId;

        $this->recordThat(new EncounterLocationChanged(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            encounterId: (string) $this->id,
            patientId: (string) $this->patient_id,
            departmentId: $newDepartmentId,
            unitId: $newUnitId,
            occurredAt: (string) now()->toISOString(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    /**
     * `CompleteEncounter` (11-encounter-domain.md §19, §22, §67; ADR-121:
     * `ACTIVE & disposition set → COMPLETED`; ADR-130, Ruling E8-R1) — the encounter's
     * own lifecycle ends. Status legality and the recorded-disposition precondition
     * are validated by EncounterLifecycle before this is called (ADR-121 — no
     * command writes status directly).
     *
     * Changes ONLY `status` (→ `COMPLETED`) and `completed_at` (→ server `now()`,
     * set once on first completion — ADR-130 C, Ruling E8-R1, Invariant 16 — never
     * overwritten on subsequent completion after reopen). `disposition` is a
     * PRECONDITION and is left exactly as it was; `encounter_type`, `arrived_at` /
     * `started_at` / `admitted_at`, `department_id` / `unit_id` and every identity
     * field are untouched. `ACTIVE → COMPLETED` leaves the
     * `enc_one_active_per_patient_facility` partial-index predicate, so the
     * transition cannot violate that index. Downstream workflows are NOT
     * completed here (Invariant 18).
     */
    public function complete(): void
    {
        $this->status = EncounterStatus::Completed;
        $this->completed_at ??= CarbonImmutable::now();

        $this->recordThat(new EncounterCompleted(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            encounterId: (string) $this->id,
            patientId: (string) $this->patient_id,
            disposition: (string) $this->disposition?->value,
            occurredAt: (string) now()->toISOString(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    /**
     * `ReopenEncounter` (11-encounter-domain.md §19, §68, §88; ADR-121:
     * `COMPLETED → ACTIVE`; ADR-130 C, Rulings E8-R1..E8-R8) — reopen a completed
     * encounter for correction. Legality (current status `COMPLETED`) is
     * validated by EncounterLifecycle before this is called (ADR-121).
     *
     * Changes ONLY `status` (→ `ACTIVE`). `completed_at` is preserved unchanged
     * (ADR-130 C, Ruling E8-R1 — set once on initial completion, never modified
     * or cleared, preserving Invariant 16). `disposition` is preserved (Ruling
     * E8-R2 — can be revised via `ChangeEncounterDisposition` if needed);
     * `encounter_type` and `admitted_at` are preserved (Ruling E8-R3 — admission
     * is not reversed); `arrived_at`, `started_at`, `department_id`, `unit_id`
     * and every identity / provenance field are untouched.
     *
     * `COMPLETED → ACTIVE` re-enters the `enc_one_active_per_patient_facility`
     * partial-index predicate; uniqueness collisions are translated by the
     * handler into `ActiveEncounterExists` (Ruling E8-R4).
     */
    public function reopen(): void
    {
        $this->status = EncounterStatus::Active;

        $this->recordThat(new EncounterReopened(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            encounterId: (string) $this->id,
            patientId: (string) $this->patient_id,
            occurredAt: (string) now()->toISOString(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    /**
     * `CancelEncounter` (11-encounter-domain.md §19, §23, §74; ADR-121:
     * `PLANNED or ARRIVED → CANCELLED`; ADR-130 A/D) — cancel an encounter
     * before active care begins. Legality (`PLANNED` or `ARRIVED`) is
     * validated by EncounterLifecycle before this is called (ADR-121).
     *
     * Changes ONLY `status` (→ `CANCELLED`). `disposition` is untouched (neither
     * required nor set — ADR-130 A); `encounter_type`, `arrived_at` (if set),
     * `department_id`, `unit_id`, and every identity / provenance field are
     * preserved. `started_at`, `admitted_at`, and `completed_at` remain null.
     *
     * If source status was `ARRIVED`, transitioning to `CANCELLED` exits the
     * `enc_one_active_per_patient_facility` partial-index predicate; if `PLANNED`,
     * it was never in the predicate.
     */
    public function cancel(): void
    {
        $this->status = EncounterStatus::Cancelled;

        $this->recordThat(new EncounterCancelled(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            encounterId: (string) $this->id,
            patientId: (string) $this->patient_id,
            occurredAt: (string) now()->toISOString(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    /**
     * `AbandonEncounter` (11-encounter-domain.md §19, §24, §76; ADR-121:
     * `ARRIVED → LEFT_WITHOUT_BEING_SEEN | ACTIVE → ABANDONED`; ADR-130 A/D) —
     * record that a patient departed before consultation or mid-care. Legality
     * (`ARRIVED` or `ACTIVE`) and target status (`LEFT_WITHOUT_BEING_SEEN` or
     * `ABANDONED`) are validated and resolved by EncounterLifecycle before this
     * is called (ADR-121).
     *
     * Changes ONLY `status` (→ `LEFT_WITHOUT_BEING_SEEN` or `→ ABANDONED`).
     * `disposition` is untouched (neither required nor set — ADR-130 A);
     * `encounter_type`, `arrived_at`, `started_at` (if set), `admitted_at` (if
     * set), `department_id`, `unit_id`, and every identity / provenance field
     * are preserved. `completed_at` remains null.
     *
     * Both terminal statuses exit the `enc_one_active_per_patient_facility`
     * partial-index predicate (`WHERE status IN ('ARRIVED', 'ACTIVE')`), freeing
     * the active-attendance slot.
     */
    public function abandon(EncounterStatus $targetStatus): void
    {
        $this->status = $targetStatus;

        $this->recordThat(new EncounterAbandoned(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            encounterId: (string) $this->id,
            patientId: (string) $this->patient_id,
            status: $this->status->value,
            occurredAt: (string) now()->toISOString(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    /**
     * `TransferEncounter` (11-encounter-domain.md §19, §25, §90, §91, §169;
     * ADR-121: `ACTIVE → TRANSFERRED`; ADR-130 A/C/E) — the patient is leaving
     * this facility for another; this attendance ends. Legality (current status
     * `ACTIVE`) is validated by EncounterLifecycle before this is called
     * (ADR-121 — no command writes status directly).
     *
     * Changes ONLY `status` (→ `TRANSFERRED`) and `disposition` (→ `TRANSFERRED`,
     * the facility-exit outcome — ADR-130 A; set unconditionally, overwriting any
     * prior value, the same way `admit()` sets `ADMITTED`). `encounter_type`,
     * `facility_id`, `arrived_at` / `started_at` / `admitted_at`, `department_id`
     * / `unit_id` and every identity / provenance field are left untouched;
     * `completed_at` stays null (the encounter did not complete — it
     * transferred). There is no `transferred_at` column (ADR-130 C/E — the time
     * is on the event and the audit row).
     *
     * The receiving facility opens its OWN encounter via its own check-in
     * (§8, §91); this method does not create it and writes no `TRANSFERRED_FROM`
     * link row (ADR-130 Deferred). `ACTIVE → TRANSFERRED` leaves the
     * `enc_one_active_per_patient_facility` partial-index predicate, so the
     * transition cannot violate that index. Downstream workflows are NOT
     * completed here (Invariant 18) — bed release (reason `TRANSFER`), referral
     * and billing react to `EncounterTransferred` on their own schedule.
     */
    public function transfer(): void
    {
        $this->status = EncounterStatus::Transferred;
        $this->disposition = EncounterDisposition::Transferred;

        $this->recordThat(new EncounterTransferred(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            encounterId: (string) $this->id,
            patientId: (string) $this->patient_id,
            occurredAt: (string) now()->toISOString(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }
}
