<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\Model;

use App\Domains\Clinical\Contracts\Event\ClinicalNoteAmended;
use App\Domains\Clinical\Contracts\Event\ClinicalNoteCreated;
use App\Domains\Clinical\Contracts\Event\ClinicalNoteSigned;
use App\Domains\Clinical\Database\Factories\ClinicalNoteFactory;
use App\Domains\Clinical\Domain\Enum\ClinicalNoteAmendmentType;
use App\Domains\Clinical\Domain\Enum\ClinicalNoteStatus;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * One clinical note — a unit of clinical documentation authored against exactly
 * one patient and one encounter (12-clinical-domain.md §10–§15; ADR-131,
 * ADR-132, ADR-134, ADR-135).
 *
 * A partitioned aggregate (ADR-131) — it owns ONLY the note and its amendment
 * rows; vitals, diagnoses, problems, allergies, plans, nursing docs and
 * procedures are their own aggregates in later increments. Every state
 * transition passes through `Clinical\Domain\Service\ClinicalNoteLifecycle`
 * (ADR-134) — no command writes `status` directly.
 *
 * `casts()` (the method) layers the enum / date casts over AggregateRoot's own
 * `$casts`; Larastan reads the property, so the effective shape is pinned in
 * `@property` tags.
 *
 * @domain      Clinical
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @facility    required — a clinical note is created at exactly one facility;
 *              `facility_id` is taken from the encounter context (ADR-135),
 *              never client input (Invariant 12).
 *
 * @property ClinicalNoteStatus $status
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $recorded_at
 * @property CarbonImmutable|null $signed_at
 * @property int $amendment_count
 */
final class ClinicalNote extends AggregateRoot
{
    /** @use HasFactory<ClinicalNoteFactory> */
    use HasFactory;

    /**
     * The authoring context for a clinical note (ADR-132 — the `authored_context`
     * column is an open coded string "CLINICIAN | NURSING | PROCEDURE | …"). The
     * WRITE PATH accepts the CLOSED set {@see AUTHORED_CONTEXTS}: `CLINICIAN`
     * (the C.1 default) and `NURSING` (increment C.8 — ADR-144; a nursing note is
     * a `ClinicalNote`, not a separate aggregate — `12` §58). Caller-supplied,
     * set-membership-validated, **not** an authorization input (no `IdentityApi`
     * / role check — the ADR-138 D8 / ADR-141 D-I pattern); it never branches
     * lifecycle, authorization, validation or handler logic.
     */
    public const AUTHORED_CONTEXT_CLINICIAN = 'CLINICIAN';

    public const AUTHORED_CONTEXT_NURSING = 'NURSING';

    /** @var list<string> */
    public const AUTHORED_CONTEXTS = [self::AUTHORED_CONTEXT_CLINICIAN, self::AUTHORED_CONTEXT_NURSING];

    protected $table = 'clin_notes';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id', 'patient_id', 'encounter_id',
        'author_user_id', 'authored_context',
        'note_type', 'title', 'body', 'status',
        'signed_at', 'signed_by_user_id', 'amendment_count',
        'occurred_at', 'recorded_at',
    ];

    /**
     * Amendment child rows recorded in-memory by {@see amend()}, drained and
     * persisted by `EloquentClinicalNoteRepository::save()` (the
     * `PatientIdentityHistory` child-persist precedent).
     *
     * @var list<ClinicalNoteAmendment>
     */
    private array $pendingAmendments = [];

    protected static function newFactory(): ClinicalNoteFactory
    {
        return ClinicalNoteFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ClinicalNoteStatus::class,
            'signed_at' => 'immutable_datetime',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'amendment_count' => 'integer',
        ];
    }

    /**
     * Originate a new note in `DRAFT` (12-clinical-domain.md §12; ADR-134). `$id`
     * is client-minted (a UUIDv7) so the `Auditable` `CreateClinicalNote`
     * command names its target before the row exists. Patient eligibility, the
     * encounter-state gate and patient/encounter consistency are the handler's
     * responsibility (via `PatientApi` / `EncounterApi`) — this factory assumes
     * they passed. `$facilityId` is the encounter context's facility (ADR-135).
     *
     * `$occurredAt` is the CLINICAL event time (may be earlier than
     * `$recordedAt` — backdating with an audit `reason` beyond 24 h is the
     * handler's concern, ADR-132); `$recordedAt` is server write time.
     *
     * `$authoredContext` is `CLINICIAN` (default) or `NURSING` (ADR-144 — a
     * nursing note is a `ClinicalNote`). Validated against {@see AUTHORED_CONTEXTS};
     * an out-of-set value is a caller bug ({@see InvalidArgumentException}).
     */
    public static function create(
        string $id,
        string $tenantId,
        string $facilityId,
        string $patientId,
        string $encounterId,
        string $authorUserId,
        string $noteType,
        ?string $title,
        string $body,
        CarbonImmutable $occurredAt,
        CarbonImmutable $recordedAt,
        string $authoredContext = self::AUTHORED_CONTEXT_CLINICIAN,
    ): self {
        self::assertAuthoredContext($authoredContext);

        $note = new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'patient_id' => $patientId,
            'encounter_id' => $encounterId,
            'author_user_id' => $authorUserId,
            'authored_context' => $authoredContext,
            'note_type' => $noteType,
            'title' => $title,
            'body' => $body,
            'status' => ClinicalNoteStatus::Draft,
            'signed_at' => null,
            'signed_by_user_id' => null,
            'amendment_count' => 0,
            'occurred_at' => $occurredAt,
            'recorded_at' => $recordedAt,
        ]);

        $note->recordThat(new ClinicalNoteCreated(
            eventId: (string) Str::uuid7(),
            tenantId: $tenantId,
            facilityId: $facilityId,
            patientId: $patientId,
            encounterId: $encounterId,
            clinicalNoteId: $id,
            noteType: $noteType,
            status: ClinicalNoteStatus::Draft->value,
            occurredAt: (string) now()->toISOString(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));

        return $note;
    }

    /**
     * `DRAFT → SIGNED` (12-clinical-domain.md §12, §13; ADR-134). Legality is
     * validated by `ClinicalNoteLifecycle::assertCanSign` before this is called.
     *
     * Changes ONLY `status`, `signed_at` (server `now()`, set once — never
     * rewritten) and `signed_by_user_id` (set once). `body` / `title` /
     * `note_type` / `occurred_at` / identity fields are untouched and become
     * immutable — corrections after this are amendments only.
     */
    public function sign(string $signedByUserId): void
    {
        $this->status = ClinicalNoteStatus::Signed;
        $this->signed_at = CarbonImmutable::now();
        $this->signed_by_user_id = $signedByUserId;

        $this->recordThat(new ClinicalNoteSigned(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            patientId: (string) $this->patient_id,
            encounterId: (string) $this->encounter_id,
            clinicalNoteId: (string) $this->id,
            occurredAt: (string) now()->toISOString(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    /**
     * `DRAFT → CANCELLED` (12-clinical-domain.md §12; ADR-134) — a note created
     * in error, discarded before signing. A PURE aggregate transition: changes
     * ONLY `status`. Records NO domain event (`12` §79 / §165 omit a
     * note-cancellation event) and is NOT separately audited (`12` §148). The
     * `clinical.note_created` audit row + the `ClinicalNoteCreated` event + the
     * queryable `CANCELLED` status are the record. Legality is validated by
     * `ClinicalNoteLifecycle::assertCanCancel` before this is called.
     */
    public function cancel(): void
    {
        $this->status = ClinicalNoteStatus::Cancelled;
    }

    /**
     * `SIGNED → SIGNED` (12-clinical-domain.md §14, §15, Invariant 6; ADR-134) —
     * append one immutable {@see ClinicalNoteAmendment}. The note `body` is
     * NEVER rewritten. `amendment_count` is incremented (it both powers the
     * derived `AMENDED` state — `status === SIGNED AND amendment_count > 0` —
     * and dirties the note row so its `version` bumps under optimistic
     * concurrency). Legality is validated by
     * `ClinicalNoteLifecycle::assertCanAmend` before this is called.
     */
    public function amend(
        ClinicalNoteAmendmentType $type,
        string $body,
        string $reason,
        string $authorUserId,
        CarbonImmutable $occurredAt,
        CarbonImmutable $recordedAt,
    ): void {
        $this->pendingAmendments[] = ClinicalNoteAmendment::record(
            id: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            clinicalNoteId: (string) $this->id,
            patientId: (string) $this->patient_id,
            encounterId: (string) $this->encounter_id,
            type: $type,
            body: $body,
            reason: $reason,
            authorUserId: $authorUserId,
            occurredAt: $occurredAt,
            recordedAt: $recordedAt,
        );

        $this->amendment_count = $this->amendment_count + 1;

        $this->recordThat(new ClinicalNoteAmended(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            patientId: (string) $this->patient_id,
            encounterId: (string) $this->encounter_id,
            clinicalNoteId: (string) $this->id,
            amendmentType: $type->value,
            occurredAt: (string) now()->toISOString(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    /**
     * `status === SIGNED` AND at least one amendment row — the DERIVED
     * `AMENDED` state (ADR-134; never a persisted `status` value).
     */
    public function isAmended(): bool
    {
        return $this->status === ClinicalNoteStatus::Signed && $this->amendment_count > 0;
    }

    /**
     * The amendment rows recorded since the last drain, clearing the buffer.
     * `EloquentClinicalNoteRepository::save()` calls this after persisting the
     * note and persists each row in the same transaction.
     *
     * @return list<ClinicalNoteAmendment>
     */
    public function releasePendingAmendments(): array
    {
        $amendments = $this->pendingAmendments;
        $this->pendingAmendments = [];

        return $amendments;
    }

    /**
     * The write path accepts only `CLINICIAN` / `NURSING` (ADR-144 — the
     * ADR-138 D8 / ADR-141 D-I set-membership discipline). `authored_context` is
     * documentation metadata, never an authorization input.
     */
    private static function assertAuthoredContext(string $authoredContext): void
    {
        if (! in_array($authoredContext, self::AUTHORED_CONTEXTS, true)) {
            throw new InvalidArgumentException(sprintf(
                'authored_context must be one of %s (ADR-144).',
                implode(' / ', self::AUTHORED_CONTEXTS),
            ));
        }
    }
}
