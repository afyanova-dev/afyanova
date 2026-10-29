<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\Model;

use App\Domains\Clinical\Contracts\Event\ProcedureAborted;
use App\Domains\Clinical\Contracts\Event\ProcedureAuthorized;
use App\Domains\Clinical\Contracts\Event\ProcedureCancelled;
use App\Domains\Clinical\Contracts\Event\ProcedureCompleted;
use App\Domains\Clinical\Contracts\Event\ProcedureDeclined;
use App\Domains\Clinical\Contracts\Event\ProcedureMaterialsConsumed;
use App\Domains\Clinical\Contracts\Event\ProcedureReady;
use App\Domains\Clinical\Contracts\Event\ProcedureRequested;
use App\Domains\Clinical\Contracts\Event\ProcedureStarted;
use App\Domains\Clinical\Database\Factories\ClinicalProcedureFactory;
use App\Domains\Clinical\Domain\Enum\ClinicalProcedureStatus;
use App\Domains\Clinical\Domain\ValueObject\ProcedureLaterality;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * One clinical procedure — this specific procedure performed on this patient,
 * during this encounter (13-clinical-procedures-domain.md §9–§53, §80–§91;
 * ADR-137, ADR-146).
 *
 * A partitioned aggregate (ADR-131) living FLAT under `app/Domains/Clinical/`
 * (ADR-146 D-A — a logical sub-area of the one Clinical module, not a physical
 * subdirectory). Every state transition passes through
 * `Clinical\Domain\Service\ClinicalProcedureLifecycle` (ADR-137) — no command
 * writes `status` directly.
 *
 * `authored_context` is `CLINICIAN` (default) or `NURSING` (ADR-146 D-E — the
 * ADR-144 mechanism; 13 §18, a procedure is frequently nurse-performed) —
 * caller-supplied, set-membership-validated, NOT an authorization input.
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
 * @facility    required — from the encounter context (ADR-135), never client
 *              input (Invariant 12).
 *
 * @property ClinicalProcedureStatus $status
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $recorded_at
 * @property CarbonImmutable|null $requested_at
 * @property CarbonImmutable|null $authorized_at
 * @property CarbonImmutable|null $ready_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 */
final class ClinicalProcedure extends AggregateRoot
{
    /** @use HasFactory<ClinicalProcedureFactory> */
    use HasFactory;

    public const AUTHORED_CONTEXT_CLINICIAN = 'CLINICIAN';

    public const AUTHORED_CONTEXT_NURSING = 'NURSING';

    /** @var list<string> */
    public const AUTHORED_CONTEXTS = [self::AUTHORED_CONTEXT_CLINICIAN, self::AUTHORED_CONTEXT_NURSING];

    private const MAX_SITE_LENGTH = 200;

    private const MAX_OUTCOME_LENGTH = 100;

    private const MAX_LOCATION_LABEL_LENGTH = 200;

    protected $table = 'clin_procedures';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id', 'patient_id', 'encounter_id',
        'author_user_id', 'authored_context',
        'procedure_number', 'status',
        'definition_id', 'definition_version', 'procedure_category',
        'requested_by_user_id', 'authorized_by_user_id', 'performed_by_user_id',
        'authorization_basis', 'authorization_protocol_reference', 'authorization_protocol_approval_id',
        'authorization_protocol_version',
        'location_label', 'site', 'laterality', 'outcome', 'outcome_detail', 'complications', 'status_reason',
        'requested_at', 'authorized_at', 'ready_at', 'started_at', 'completed_at',
        'source_diagnosis_id', 'source_clinical_plan_id', 'indication', 'clinical_note_id',
        'financial_clearance_ref', 'financial_policy_version',
        'occurred_at', 'recorded_at',
    ];

    /**
     * Append-only child rows recorded in-memory by {@see amend()} and
     * {@see recordMaterialsConsumed()}, drained and persisted by
     * `EloquentClinicalProcedureRepository::save()` (the `ClinicalNote` /
     * `ClinicalNoteAmendment` child-persist precedent).
     *
     * @var list<ProcedureAmendment>
     */
    private array $pendingAmendments = [];

    /** @var list<ProcedureMaterial> */
    private array $pendingMaterials = [];

    protected static function newFactory(): ClinicalProcedureFactory
    {
        return ClinicalProcedureFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ClinicalProcedureStatus::class,
            'definition_version' => 'integer',
            'requested_at' => 'immutable_datetime',
            'authorized_at' => 'immutable_datetime',
            'ready_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    /**
     * Originate a new procedure in `REQUESTED` (13 §120; ADR-146 D-G). `$id` is
     * client-minted (a UUIDv7) so the `Auditable` `RequestProcedure` command
     * names its target before the row exists. Patient / encounter eligibility,
     * the capability gate and provenance validation are the handler's
     * responsibility; this factory assumes they passed. `$facilityId` is the
     * encounter context's facility (ADR-135). `$definitionVersion` /
     * `$procedureCategory` are SNAPSHOTS of the referenced `ProcedureDefinition`
     * at request time (13 §112).
     */
    public static function request(
        string $id,
        string $tenantId,
        string $facilityId,
        string $patientId,
        string $encounterId,
        string $authorUserId,
        string $authoredContext,
        string $procedureNumber,
        string $definitionId,
        int $definitionVersion,
        ?string $procedureCategory,
        ?string $indication,
        ?string $sourceDiagnosisId,
        ?string $sourceClinicalPlanId,
        ?string $financialClearanceRef,
        ?string $financialPolicyVersion,
        CarbonImmutable $occurredAt,
        CarbonImmutable $recordedAt,
    ): self {
        self::assertAuthoredContext($authoredContext);

        $procedure = new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'patient_id' => $patientId,
            'encounter_id' => $encounterId,
            'author_user_id' => $authorUserId,
            'authored_context' => $authoredContext,
            'procedure_number' => $procedureNumber,
            'status' => ClinicalProcedureStatus::Requested,
            'definition_id' => $definitionId,
            'definition_version' => $definitionVersion,
            'procedure_category' => $procedureCategory,
            'requested_by_user_id' => $authorUserId,
            'authorized_by_user_id' => null,
            'authorization_basis' => null,
            'authorization_protocol_reference' => null,
            'authorization_protocol_approval_id' => null,
            'authorization_protocol_version' => null,
            'performed_by_user_id' => null,
            'location_label' => null,
            'site' => null,
            'laterality' => null,
            'outcome' => null,
            'outcome_detail' => null,
            'complications' => null,
            'status_reason' => null,
            'requested_at' => $recordedAt,
            'authorized_at' => null,
            'ready_at' => null,
            'started_at' => null,
            'completed_at' => null,
            'source_diagnosis_id' => $sourceDiagnosisId,
            'source_clinical_plan_id' => $sourceClinicalPlanId,
            'indication' => self::blankToNull($indication),
            'clinical_note_id' => null,
            'financial_clearance_ref' => self::blankToNull($financialClearanceRef),
            'financial_policy_version' => self::blankToNull($financialPolicyVersion),
            'occurred_at' => $occurredAt,
            'recorded_at' => $recordedAt,
        ]);

        $procedure->recordThat(new ProcedureRequested(
            eventId: (string) Str::uuid7(),
            tenantId: $tenantId,
            facilityId: $facilityId,
            patientId: $patientId,
            encounterId: $encounterId,
            procedureId: $id,
            definitionId: $definitionId,
            procedureCategory: $procedureCategory,
            status: ClinicalProcedureStatus::Requested->value,
            occurredAt: (string) now()->toISOString(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));

        return $procedure;
    }

    /**
     * `AuthorizeProcedure: REQUESTED → AUTHORIZED` (13 §21). Legality is
     * validated by `ClinicalProcedureLifecycle::assertCanAuthorize` before this
     * is called. `authorized_at` is set once, server time.
     */
    public function authorize(
        string $authorizedByUserId,
        ?string $protocolReference = null,
        ?string $protocolApprovalId = null,
        ?string $protocolVersion = null,
    ): void
    {
        if ($protocolReference !== null && ($protocolApprovalId === null || $protocolVersion === null)) {
            throw new InvalidArgumentException('Protocol authorization requires an approval record and protocol version.');
        }
        $this->status = ClinicalProcedureStatus::Authorized;
        $this->authorized_by_user_id = $authorizedByUserId;
        $this->authorization_basis = $protocolReference === null ? 'CLINICIAN' : 'APPROVED_PROTOCOL';
        $this->authorization_protocol_reference = $protocolReference;
        $this->authorization_protocol_approval_id = $protocolApprovalId;
        $this->authorization_protocol_version = $protocolVersion;
        $this->authorized_at = CarbonImmutable::now();

        $this->recordThat(new ProcedureAuthorized(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            patientId: (string) $this->patient_id,
            encounterId: (string) $this->encounter_id,
            procedureId: (string) $this->id,
            definitionId: (string) $this->definition_id,
            procedureCategory: $this->procedure_category,
            status: ClinicalProcedureStatus::Authorized->value,
            occurredAt: (string) now()->toISOString(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    /**
     * `MarkProcedureReady: AUTHORIZED → READY` — entered only when the
     * definition requires a readiness check (ADR-146 D-F). Legality (incl. the
     * flag) is validated by
     * `ClinicalProcedureLifecycle::assertCanMarkReady` before this is called.
     */
    public function markReady(): void
    {
        $this->status = ClinicalProcedureStatus::Ready;
        $this->ready_at = CarbonImmutable::now();

        $this->recordThat(new ProcedureReady(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            patientId: (string) $this->patient_id,
            encounterId: (string) $this->encounter_id,
            procedureId: (string) $this->id,
            definitionId: (string) $this->definition_id,
            procedureCategory: $this->procedure_category,
            status: ClinicalProcedureStatus::Ready->value,
            occurredAt: (string) now()->toISOString(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    /**
     * `StartProcedure: {AUTHORIZED, READY} → IN_PROGRESS`. Legality (incl. the
     * fast-path readiness rule) is validated by
     * `ClinicalProcedureLifecycle::assertCanStart` before this is called.
     * `$performedByUserId` is set ONCE here (13 §19 — may differ from the
     * requester / authorizer). `$site` / `$laterality` are validated against
     * the definition's `requires_site` / `requires_laterality` flags
     * (ADR-146 D-H) — the performer knows the site when about to act.
     */
    public function start(
        string $performedByUserId,
        bool $requiresSite,
        bool $requiresLaterality,
        ?string $site,
        ?string $laterality,
    ): void {
        $site = self::normalizeOptional($site, self::MAX_SITE_LENGTH, 'site');
        if ($requiresSite && $site === null) {
            throw new InvalidArgumentException('StartProcedure::$site is required by this procedure\'s definition (ADR-146 D-H).');
        }

        if ($laterality !== null) {
            $laterality = ProcedureLaterality::assert($laterality);
        } elseif ($requiresLaterality) {
            throw new InvalidArgumentException('StartProcedure::$laterality is required by this procedure\'s definition (ADR-146 D-H).');
        }

        $this->status = ClinicalProcedureStatus::InProgress;
        $this->performed_by_user_id = $performedByUserId;
        $this->site = $site;
        $this->laterality = $laterality;
        $this->started_at = CarbonImmutable::now();

        $this->recordThat(new ProcedureStarted(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            patientId: (string) $this->patient_id,
            encounterId: (string) $this->encounter_id,
            procedureId: (string) $this->id,
            definitionId: (string) $this->definition_id,
            procedureCategory: $this->procedure_category,
            status: ClinicalProcedureStatus::InProgress->value,
            occurredAt: (string) now()->toISOString(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    /**
     * `CompleteProcedure: IN_PROGRESS → COMPLETED` — the terminal business fact
     * (13 §33; the Billing trigger). Legality is validated by
     * `ClinicalProcedureLifecycle::assertCanComplete` before this is called.
     * `$outcome` / `$outcomeDetail` / `$complications` are OPTIONAL at
     * completion (ADR-146 D-I) — `RecordProcedureOutcome` / `AmendProcedure`
     * may set or revise them later, subject to their own lifecycle rules.
     */
    public function complete(?string $outcome, ?string $outcomeDetail, ?string $complications): void
    {
        $this->status = ClinicalProcedureStatus::Completed;
        $this->outcome = self::normalizeOptional($outcome, self::MAX_OUTCOME_LENGTH, 'outcome');
        $this->outcome_detail = self::blankToNull($outcomeDetail);
        $this->complications = self::blankToNull($complications);
        $this->completed_at = CarbonImmutable::now();

        $this->recordThat(new ProcedureCompleted(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            patientId: (string) $this->patient_id,
            encounterId: (string) $this->encounter_id,
            procedureId: (string) $this->id,
            definitionId: (string) $this->definition_id,
            procedureCategory: $this->procedure_category,
            status: ClinicalProcedureStatus::Completed->value,
            outcome: $this->outcome,
            occurredAt: (string) now()->toISOString(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    /**
     * `CancelProcedure: {REQUESTED, AUTHORIZED, READY} → CANCELLED` (13 §34).
     * Legality is validated by `ClinicalProcedureLifecycle::assertCanCancel`
     * before this is called. `$reason` is OPTIONAL (ADR-146 D-J).
     */
    public function cancel(?string $reason): void
    {
        $this->status = ClinicalProcedureStatus::Cancelled;
        $this->status_reason = self::blankToNull($reason);

        $this->recordThat(new ProcedureCancelled(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            patientId: (string) $this->patient_id,
            encounterId: (string) $this->encounter_id,
            procedureId: (string) $this->id,
            definitionId: (string) $this->definition_id,
            procedureCategory: $this->procedure_category,
            status: ClinicalProcedureStatus::Cancelled->value,
            occurredAt: (string) now()->toISOString(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    /**
     * `AbortProcedure: IN_PROGRESS → ABORTED` (13 §35 — distinct from
     * cancellation before starting). Legality is validated by
     * `ClinicalProcedureLifecycle::assertCanAbort` before this is called.
     * `$reason` is MANDATORY (ADR-146 D-J — a stopped procedure is a
     * safety / governance event).
     */
    public function abort(string $reason): void
    {
        $trimmed = trim($reason);
        if ($trimmed === '') {
            throw new InvalidArgumentException('AbortProcedure::$reason is required (ADR-146 D-J).');
        }

        $this->status = ClinicalProcedureStatus::Aborted;
        $this->status_reason = $trimmed;

        $this->recordThat(new ProcedureAborted(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            patientId: (string) $this->patient_id,
            encounterId: (string) $this->encounter_id,
            procedureId: (string) $this->id,
            definitionId: (string) $this->definition_id,
            procedureCategory: $this->procedure_category,
            status: ClinicalProcedureStatus::Aborted->value,
            occurredAt: (string) now()->toISOString(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    /**
     * `DeclineProcedure: {AUTHORIZED, READY} → DECLINED` (13 §37). Legality is
     * validated by `ClinicalProcedureLifecycle::assertCanDecline` before this
     * is called. `$reason` is OPTIONAL (ADR-146 D-J).
     */
    public function decline(?string $reason): void
    {
        $this->status = ClinicalProcedureStatus::Declined;
        $this->status_reason = self::blankToNull($reason);

        $this->recordThat(new ProcedureDeclined(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            patientId: (string) $this->patient_id,
            encounterId: (string) $this->encounter_id,
            procedureId: (string) $this->id,
            definitionId: (string) $this->definition_id,
            procedureCategory: $this->procedure_category,
            status: ClinicalProcedureStatus::Declined->value,
            occurredAt: (string) now()->toISOString(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    /**
     * `RecordProcedureOutcome` — sets / revises `outcome` / `outcome_detail`
     * while `IN_PROGRESS` ONLY (ADR-146 D-I / S3 / O-2). Legality is validated
     * by `ClinicalProcedureLifecycle::assertCanRecordOutcome` before this is
     * called. Records NO domain event (no PHASE-6 consumer needs an
     * intermediate outcome signal — `ProcedureCompleted` carries the final
     * value).
     */
    public function recordOutcome(?string $outcome, ?string $outcomeDetail): void
    {
        $this->outcome = self::normalizeOptional($outcome, self::MAX_OUTCOME_LENGTH, 'outcome');
        $this->outcome_detail = self::blankToNull($outcomeDetail);
    }

    /**
     * `AddProcedureNote` — links the `ClinicalNote` created through the
     * unchanged C.1 write path (ADR-146 D-L). Legality is validated by
     * `ClinicalProcedureLifecycle::assertCanAddNote` before this is called.
     * Records NO domain event — the C.1 `ClinicalNoteCreated` event already
     * fired on the note's own aggregate.
     */
    public function linkClinicalNote(string $clinicalNoteId): void
    {
        $this->clinical_note_id = $clinicalNoteId;
    }

    /**
     * `AmendProcedure` — the privileged post-execution correction (ADR-146
     * D-M). Legality is validated by `ClinicalProcedureLifecycle::assertCanAmend`
     * before this is called. Applies the caller-supplied new values for the
     * amendable fields directly onto this row (so `outcome` / `site` / … stay
     * queryable as current truth — the `RecordProcedureOutcome` /
     * `AmendProcedure` split only makes sense if `AmendProcedure` can actually
     * revise them) AND appends one `clin_procedure_amendments` row capturing
     * the correction narrative, the required `reason`, and WHICH fields this
     * amendment touched — a completed record is never silently mutated outside
     * this controlled, fully-audited path (13 §81, §142 Inv 6).
     *
     * IMMUTABLE always (not accepted here): id, procedure_number, definition_id,
     * definition_version, patient_id, encounter_id, facility_id, status, every
     * transition timestamp, requested_by / authorized_by attribution.
     *
     * @param  array{outcome?: ?string, outcomeDetail?: ?string, complications?: ?string, site?: ?string, laterality?: ?string, performedByUserId?: ?string, statusReason?: ?string, locationLabel?: ?string}  $fields
     *                                                                                                                                                                                                                  only keys present are changed; the array's own keys become `changed_fields`.
     */
    public function amend(
        string $amendmentType,
        string $body,
        string $reason,
        array $fields,
        string $authorUserId,
        CarbonImmutable $occurredAt,
        CarbonImmutable $recordedAt,
    ): void {
        $changedFields = [];

        if (array_key_exists('outcome', $fields)) {
            $this->outcome = self::normalizeOptional($fields['outcome'], self::MAX_OUTCOME_LENGTH, 'outcome');
            $changedFields[] = 'outcome';
        }
        if (array_key_exists('outcomeDetail', $fields)) {
            $this->outcome_detail = self::blankToNull($fields['outcomeDetail']);
            $changedFields[] = 'outcome_detail';
        }
        if (array_key_exists('complications', $fields)) {
            $this->complications = self::blankToNull($fields['complications']);
            $changedFields[] = 'complications';
        }
        if (array_key_exists('site', $fields)) {
            $this->site = self::normalizeOptional($fields['site'], self::MAX_SITE_LENGTH, 'site');
            $changedFields[] = 'site';
        }
        if (array_key_exists('laterality', $fields)) {
            $this->laterality = $fields['laterality'] !== null ? ProcedureLaterality::assert($fields['laterality']) : null;
            $changedFields[] = 'laterality';
        }
        if (array_key_exists('performedByUserId', $fields) && $fields['performedByUserId'] !== null) {
            $this->performed_by_user_id = $fields['performedByUserId'];
            $changedFields[] = 'performed_by_user_id';
        }
        if (array_key_exists('statusReason', $fields)) {
            $this->status_reason = self::blankToNull($fields['statusReason']);
            $changedFields[] = 'status_reason';
        }
        if (array_key_exists('locationLabel', $fields)) {
            $this->location_label = self::normalizeOptional($fields['locationLabel'], self::MAX_LOCATION_LABEL_LENGTH, 'locationLabel');
            $changedFields[] = 'location_label';
        }

        $this->pendingAmendments[] = ProcedureAmendment::record(
            id: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            procedureId: (string) $this->id,
            patientId: (string) $this->patient_id,
            encounterId: (string) $this->encounter_id,
            amendmentType: $amendmentType,
            body: $body,
            reason: $reason,
            changedFields: $changedFields,
            authorUserId: $authorUserId,
            occurredAt: $occurredAt,
            recordedAt: $recordedAt,
        );
        // No domain event — no PHASE-6 consumer needs a procedure-amendment
        // signal (ADR-129/130 discipline; ADR-146 D-R).
    }

    /**
     * `RecordProcedureMaterials` — appends one `clin_procedure_materials` row
     * per supplied material line and emits ONE `ProcedureMaterialsConsumed`
     * event carrying every line (ADR-146 D-N). Legality is validated by
     * `ClinicalProcedureLifecycle::assertCanRecordMaterials` before this is
     * called.
     *
     * @param  list<array{materialRef: string, materialCodeSystem: ?string, quantity: float, unit: ?string, batchRef: ?string}>  $materials
     */
    public function recordMaterialsConsumed(array $materials, string $recordedByUserId, CarbonImmutable $recordedAt): void
    {
        $payload = [];

        foreach ($materials as $material) {
            $this->pendingMaterials[] = ProcedureMaterial::record(
                id: (string) Str::uuid7(),
                tenantId: (string) $this->tenant_id,
                procedureId: (string) $this->id,
                materialRef: $material['materialRef'],
                materialCodeSystem: $material['materialCodeSystem'],
                quantity: $material['quantity'],
                unit: $material['unit'],
                batchRef: $material['batchRef'],
                recordedByUserId: $recordedByUserId,
                recordedAt: $recordedAt,
            );

            $payload[] = [
                'materialRef' => $material['materialRef'],
                'quantity' => $material['quantity'],
                'unit' => $material['unit'],
                'batchRef' => $material['batchRef'],
            ];
        }

        $this->recordThat(new ProcedureMaterialsConsumed(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            patientId: (string) $this->patient_id,
            encounterId: (string) $this->encounter_id,
            procedureId: (string) $this->id,
            definitionId: (string) $this->definition_id,
            materials: $payload,
            occurredAt: (string) now()->toISOString(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    /**
     * The amendment rows recorded since the last drain, clearing the buffer.
     * `EloquentClinicalProcedureRepository::save()` calls this after
     * persisting the procedure and persists each row in the same transaction.
     *
     * @return list<ProcedureAmendment>
     */
    public function releasePendingAmendments(): array
    {
        $amendments = $this->pendingAmendments;
        $this->pendingAmendments = [];

        return $amendments;
    }

    /**
     * @return list<ProcedureMaterial>
     */
    public function releasePendingMaterials(): array
    {
        $materials = $this->pendingMaterials;
        $this->pendingMaterials = [];

        return $materials;
    }

    private static function assertAuthoredContext(string $authoredContext): void
    {
        if (! in_array($authoredContext, self::AUTHORED_CONTEXTS, true)) {
            throw new InvalidArgumentException(sprintf(
                'authored_context must be one of %s (ADR-146 D-E; the ADR-144 mechanism).',
                implode(' / ', self::AUTHORED_CONTEXTS),
            ));
        }
    }

    private static function normalizeOptional(?string $value, int $maxLength, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        if (mb_strlen($trimmed) > $maxLength) {
            throw new InvalidArgumentException(sprintf(
                'ClinicalProcedure::$%s must be at most %d characters (ADR-146).',
                $field,
                $maxLength,
            ));
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
