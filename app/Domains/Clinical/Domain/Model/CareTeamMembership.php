<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\Model;

use App\Domains\Clinical\Contracts\Event\CareTeamMemberAdded;
use App\Domains\Clinical\Contracts\Event\CareTeamMemberRemoved;
use App\Domains\Clinical\Contracts\Event\ProviderOfRecordChanged;
use App\Domains\Clinical\Database\Factories\CareTeamMembershipFactory;
use App\Domains\Clinical\Domain\Exception\IllegalCareTeamMembershipTransition;
use App\Domains\Clinical\Domain\ValueObject\TeamRole;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * One staff member holding one role on one encounter for one bounded period —
 * an encounter-scoped clinical-responsibility assignment, optionally carrying
 * the encounter's provider-of-record designation (12-clinical-domain.md §4.1,
 * §162; ADR-130, ADR-131, ADR-132, ADR-135, ADR-142).
 *
 * `CareTeamMembership` is the aggregate TYPE (ADR-142 D-C); each row is an
 * INDEPENDENT aggregate instance with its own `version` / optimistic-concurrency
 * boundary. There is NO `CareTeam` aggregate root — "the care team" is the
 * derived collection of an encounter's active rows, materialised only by
 * `CareTeamMembershipReader`. The cross-membership "≤ 1 active provider-of-record
 * per encounter" invariant is enforced authoritatively by the database partial
 * unique index `clin_one_provider_of_record_per_encounter` (ADR-142 D-T; IC
 * §9.1), NOT by this aggregate.
 *
 * ORIGIN (ADR-142 D-B / D-L / D-M): `encounter_id`, `facility_id` and
 * `patient_id` are the ORIGINATING encounter's, taken from the encounter
 * context at `AddCareTeamMember` (never client input — Invariant 12), immutable
 * for life. `facility_id` is the authorization / originating-facility-visibility
 * anchor.
 *
 * LIFECYCLE (ADR-142 D-I): `ACTIVE (active_to IS NULL) → ENDED (active_to set
 * once, never rewritten)`. ENDED is TERMINAL — no reactivation, no suspension,
 * no replacement command; re-add the person with a fresh `AddCareTeamMember`.
 * `team_role` is IMMUTABLE (a role change is end + add). There is NO pre-change
 * history table — the retained ended rows plus the `ProviderOfRecordChanged`
 * event / audit stream ARE the history (ADR-142 D-J / D-V).
 *
 * PROVIDER-OF-RECORD (ADR-142 D-G / D-J): `is_provider_of_record` is a boolean
 * flag on the membership. Zero active provider-of-record per encounter is valid;
 * at most one. `ChangeProviderOfRecord` is an application-level atomic hand-off
 * across two instances ({@see relinquishProviderOfRecord()} on the outgoing,
 * {@see assignAsProviderOfRecord()} on the incoming) that carries NO rationale
 * (reconciliation R-1). Ending the provider-of-record clears the designation.
 *
 * @domain      Clinical
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @facility    required — the ORIGINATING facility (ADR-142 D-B / D-M); from the
 *              encounter context (ADR-135), never client input (Invariant 12).
 *
 * @property bool $is_provider_of_record
 * @property CarbonImmutable $active_from
 * @property CarbonImmutable|null $active_to
 * @property CarbonImmutable $created_at
 */
final class CareTeamMembership extends AggregateRoot
{
    /** @use HasFactory<CareTeamMembershipFactory> */
    use HasFactory;

    protected $table = 'clin_care_team_memberships';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id', 'encounter_id', 'patient_id',
        'staff_user_id', 'team_role', 'is_provider_of_record',
        'active_from', 'active_to', 'added_by_user_id', 'removed_by_user_id', 'end_reason',
    ];

    protected static function newFactory(): CareTeamMembershipFactory
    {
        return CareTeamMembershipFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_provider_of_record' => 'boolean',
            'active_from' => 'immutable_datetime',
            'active_to' => 'immutable_datetime',
        ];
    }

    /**
     * Add a staff member to an encounter's care team, ACTIVE (ADR-142 D-D …
     * D-K). `$id` is client-minted (a UUIDv7) so the `Auditable`
     * `AddCareTeamMember` command names its target before the row exists (the
     * `AddProblem` / `RecordAllergy` precedent). Staff validation
     * (`IdentityApi::isStaff`), the patient-state gate, the encounter-state gate
     * and the per-encounter-facility authorization refinement are the handler's
     * responsibility; `$facilityId` / `$patientId` are the encounter context's,
     * never this call's caller (ADR-142 D-B / D-L).
     *
     * `$activeFrom` is server `now()` — never caller-supplied, no backdating
     * (ADR-142 D-I / D-S). When `$isProviderOfRecord` is true (permitted by the
     * handler ONLY when the encounter has no active provider-of-record) a
     * `ProviderOfRecordChanged` event is recorded alongside `CareTeamMemberAdded`
     * (ADR-142 D-R).
     */
    public static function add(
        string $id,
        string $tenantId,
        string $facilityId,
        string $encounterId,
        string $patientId,
        string $staffUserId,
        string $teamRole,
        bool $isProviderOfRecord,
        string $addedByUserId,
        CarbonImmutable $activeFrom,
    ): self {
        $teamRole = TeamRole::assert($teamRole);

        $membership = new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'encounter_id' => $encounterId,
            'patient_id' => $patientId,
            'staff_user_id' => $staffUserId,
            'team_role' => $teamRole,
            'is_provider_of_record' => $isProviderOfRecord,
            'active_from' => $activeFrom,
            'active_to' => null,
            'added_by_user_id' => $addedByUserId,
            'removed_by_user_id' => null,
            'end_reason' => null,
        ]);

        $membership->recordThat(new CareTeamMemberAdded(
            eventId: (string) Str::uuid7(),
            tenantId: $tenantId,
            facilityId: $facilityId,
            patientId: $patientId,
            encounterId: $encounterId,
            careTeamMembershipId: $id,
            staffUserId: $staffUserId,
            teamRole: $teamRole,
            isProviderOfRecord: $isProviderOfRecord,
            occurredAt: self::nowIso(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));

        if ($isProviderOfRecord) {
            $membership->recordThat(new ProviderOfRecordChanged(
                eventId: (string) Str::uuid7(),
                tenantId: $tenantId,
                facilityId: $facilityId,
                patientId: $patientId,
                encounterId: $encounterId,
                previousProviderStaffUserId: null,
                previousProviderMembershipId: null,
                newProviderStaffUserId: $staffUserId,
                newProviderMembershipId: $id,
                occurredAt: self::nowIso(),
                correlationId: CorrelationId::get(),
                causationId: Causation::get(),
            ));
        }

        return $membership;
    }

    /** This membership is currently active (`active_to` is unset). */
    public function isActive(): bool
    {
        return $this->active_to === null;
    }

    /** This membership is the encounter's current active provider-of-record. */
    public function isProviderOfRecord(): bool
    {
        return $this->is_provider_of_record && $this->isActive();
    }

    /**
     * End this membership — `ACTIVE → ENDED` (ADR-142 D-I). Sets `active_to`
     * (server clinical time, set once), `removed_by_user_id` and the optional
     * `end_reason` (row only — never an event, never audit — ADR-142 D-H). An
     * already-ENDED membership is rejected (`IllegalCareTeamMembershipTransition`,
     * 409 — not idempotent, no reactivation).
     *
     * If this membership was the encounter's provider-of-record, the
     * designation is cleared and a `ProviderOfRecordChanged` event (with
     * `newProviderStaffUserId = null`) is recorded alongside
     * `CareTeamMemberRemoved` (ADR-142 D-J / D-R). The encounter is then left
     * with no provider-of-record, which is valid (ADR-142 D-G).
     */
    public function end(string $removedByUserId, ?string $endReason, CarbonImmutable $endedAt): void
    {
        if (! $this->isActive()) {
            throw IllegalCareTeamMembershipTransition::endTerminal();
        }

        $wasProviderOfRecord = (bool) $this->is_provider_of_record;

        $this->active_to = $endedAt;
        $this->removed_by_user_id = $removedByUserId;
        $this->end_reason = ($endReason !== null && trim($endReason) !== '') ? trim($endReason) : null;

        if ($wasProviderOfRecord) {
            $this->is_provider_of_record = false;
        }

        $this->recordThat(new CareTeamMemberRemoved(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            patientId: (string) $this->patient_id,
            encounterId: (string) $this->encounter_id,
            careTeamMembershipId: (string) $this->id,
            staffUserId: (string) $this->staff_user_id,
            teamRole: (string) $this->team_role,
            wasProviderOfRecord: $wasProviderOfRecord,
            occurredAt: self::nowIso(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));

        if ($wasProviderOfRecord) {
            $this->recordThat(new ProviderOfRecordChanged(
                eventId: (string) Str::uuid7(),
                tenantId: (string) $this->tenant_id,
                facilityId: (string) $this->facility_id,
                patientId: (string) $this->patient_id,
                encounterId: (string) $this->encounter_id,
                previousProviderStaffUserId: (string) $this->staff_user_id,
                previousProviderMembershipId: (string) $this->id,
                newProviderStaffUserId: null,
                newProviderMembershipId: null,
                occurredAt: self::nowIso(),
                correlationId: CorrelationId::get(),
                causationId: Causation::get(),
            ));
        }
    }

    /**
     * Designate this membership as the encounter's provider-of-record and record
     * the single `ProviderOfRecordChanged` event for the transition (ADR-142
     * D-J / D-R). Called on the INCOMING membership by
     * `ChangeProviderOfRecordHandler` (and never for a first assignment via
     * `AddCareTeamMember` — that path is {@see add()}).
     *
     * `$previousProviderStaffUserId` / `$previousProviderMembershipId` describe
     * the outgoing provider-of-record, or null for a first assignment. The
     * outgoing membership's flag is cleared separately by
     * {@see relinquishProviderOfRecord()} (which records no event — this method
     * carries the single transition event).
     */
    public function assignAsProviderOfRecord(
        ?string $previousProviderStaffUserId,
        ?string $previousProviderMembershipId,
        CarbonImmutable $changedAt,
    ): void {
        if (! $this->isActive()) {
            throw IllegalCareTeamMembershipTransition::providerOfRecordRequiresActive();
        }

        $this->is_provider_of_record = true;

        $this->recordThat(new ProviderOfRecordChanged(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            patientId: (string) $this->patient_id,
            encounterId: (string) $this->encounter_id,
            previousProviderStaffUserId: $previousProviderStaffUserId,
            previousProviderMembershipId: $previousProviderMembershipId,
            newProviderStaffUserId: (string) $this->staff_user_id,
            newProviderMembershipId: (string) $this->id,
            occurredAt: (string) $changedAt->toISOString(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    /**
     * Clear this membership's provider-of-record designation WITHOUT ending the
     * membership (ADR-142 D-J — "the outgoing person REMAINS a care-team
     * member"). Records NO event — the transition's single
     * `ProviderOfRecordChanged` is recorded by
     * {@see assignAsProviderOfRecord()} on the incoming membership.
     */
    public function relinquishProviderOfRecord(): void
    {
        $this->is_provider_of_record = false;
    }

    private static function nowIso(): string
    {
        return (string) CarbonImmutable::now()->toISOString();
    }
}
