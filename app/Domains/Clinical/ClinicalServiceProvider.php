<?php

declare(strict_types=1);

namespace App\Domains\Clinical;

use App\Domains\Clinical\Application\ClinicalApiService;
use App\Domains\Clinical\Application\Command\AbortProcedure;
use App\Domains\Clinical\Application\Command\AbortProcedureHandler;
use App\Domains\Clinical\Application\Command\AddCareTeamMember;
use App\Domains\Clinical\Application\Command\AddCareTeamMemberHandler;
use App\Domains\Clinical\Application\Command\AddProblem;
use App\Domains\Clinical\Application\Command\AddProblemHandler;
use App\Domains\Clinical\Application\Command\AddProcedureNote;
use App\Domains\Clinical\Application\Command\AddProcedureNoteHandler;
use App\Domains\Clinical\Application\Command\AmendClinicalNote;
use App\Domains\Clinical\Application\Command\AmendClinicalNoteHandler;
use App\Domains\Clinical\Application\Command\AmendProcedure;
use App\Domains\Clinical\Application\Command\AmendProcedureHandler;
use App\Domains\Clinical\Application\Command\AuthorizeProcedure;
use App\Domains\Clinical\Application\Command\AuthorizeProcedureHandler;
use App\Domains\Clinical\Application\Command\CancelClinicalNote;
use App\Domains\Clinical\Application\Command\CancelClinicalNoteHandler;
use App\Domains\Clinical\Application\Command\CancelProcedure;
use App\Domains\Clinical\Application\Command\CancelProcedureHandler;
use App\Domains\Clinical\Application\Command\ChangeProviderOfRecord;
use App\Domains\Clinical\Application\Command\ChangeProviderOfRecordHandler;
use App\Domains\Clinical\Application\Command\CloseClinicalPlan;
use App\Domains\Clinical\Application\Command\CloseClinicalPlanHandler;
use App\Domains\Clinical\Application\Command\CompleteProcedure;
use App\Domains\Clinical\Application\Command\CompleteProcedureHandler;
use App\Domains\Clinical\Application\Command\CorrectDiagnosis;
use App\Domains\Clinical\Application\Command\CorrectDiagnosisHandler;
use App\Domains\Clinical\Application\Command\CorrectPrescribingDecision;
use App\Domains\Clinical\Application\Command\CorrectPrescribingDecisionHandler;
use App\Domains\Clinical\Application\Command\CorrectTriageAssessment;
use App\Domains\Clinical\Application\Command\CorrectTriageAssessmentHandler;
use App\Domains\Clinical\Application\Command\CorrectVitalSignsObservation;
use App\Domains\Clinical\Application\Command\CorrectVitalSignsObservationHandler;
use App\Domains\Clinical\Application\Command\CreateClinicalNote;
use App\Domains\Clinical\Application\Command\CreateClinicalNoteHandler;
use App\Domains\Clinical\Application\Command\CreateClinicalPlan;
use App\Domains\Clinical\Application\Command\CreateClinicalPlanHandler;
use App\Domains\Clinical\Application\Command\DeclineProcedure;
use App\Domains\Clinical\Application\Command\DeclineProcedureHandler;
use App\Domains\Clinical\Application\Command\DiscontinuePrescribingDecision;
use App\Domains\Clinical\Application\Command\DiscontinuePrescribingDecisionHandler;
use App\Domains\Clinical\Application\Command\EndCareTeamMembership;
use App\Domains\Clinical\Application\Command\EndCareTeamMembershipHandler;
use App\Domains\Clinical\Application\Command\MarkProcedureReady;
use App\Domains\Clinical\Application\Command\MarkProcedureReadyHandler;
use App\Domains\Clinical\Application\Command\RecordAllergy;
use App\Domains\Clinical\Application\Command\RecordAllergyHandler;
use App\Domains\Clinical\Application\Command\RecordDiagnosis;
use App\Domains\Clinical\Application\Command\RecordDiagnosisHandler;
use App\Domains\Clinical\Application\Command\RecordPrescribingDecision;
use App\Domains\Clinical\Application\Command\RecordPrescribingDecisionHandler;
use App\Domains\Clinical\Application\Command\RecordProcedureMaterials;
use App\Domains\Clinical\Application\Command\RecordProcedureMaterialsHandler;
use App\Domains\Clinical\Application\Command\RecordProcedureOutcome;
use App\Domains\Clinical\Application\Command\RecordProcedureOutcomeHandler;
use App\Domains\Clinical\Application\Command\RecordTriageAssessment;
use App\Domains\Clinical\Application\Command\RecordTriageAssessmentHandler;
use App\Domains\Clinical\Application\Command\RecordVitalSigns;
use App\Domains\Clinical\Application\Command\RecordVitalSignsHandler;
use App\Domains\Clinical\Application\Command\RequestProcedure;
use App\Domains\Clinical\Application\Command\RequestProcedureHandler;
use App\Domains\Clinical\Application\Command\ResolveAllergy;
use App\Domains\Clinical\Application\Command\ResolveAllergyHandler;
use App\Domains\Clinical\Application\Command\ResolveProblem;
use App\Domains\Clinical\Application\Command\ResolveProblemHandler;
use App\Domains\Clinical\Application\Command\SignClinicalNote;
use App\Domains\Clinical\Application\Command\SignClinicalNoteHandler;
use App\Domains\Clinical\Application\Command\StartProcedure;
use App\Domains\Clinical\Application\Command\StartProcedureHandler;
use App\Domains\Clinical\Application\Command\UpdateAllergy;
use App\Domains\Clinical\Application\Command\UpdateAllergyHandler;
use App\Domains\Clinical\Application\Command\UpdateClinicalPlan;
use App\Domains\Clinical\Application\Command\UpdateClinicalPlanHandler;
use App\Domains\Clinical\Application\Command\UpdateProblem;
use App\Domains\Clinical\Application\Command\UpdateProblemHandler;
use App\Domains\Clinical\Application\Command\VerifyAllergy;
use App\Domains\Clinical\Application\Command\VerifyAllergyHandler;
use App\Domains\Clinical\Application\Query\GetAllergy;
use App\Domains\Clinical\Application\Query\GetAllergyHandler;
use App\Domains\Clinical\Application\Query\GetCareTeamMembership;
use App\Domains\Clinical\Application\Query\GetCareTeamMembershipHandler;
use App\Domains\Clinical\Application\Query\GetClinicalNote;
use App\Domains\Clinical\Application\Query\GetClinicalNoteHandler;
use App\Domains\Clinical\Application\Query\GetClinicalPlan;
use App\Domains\Clinical\Application\Query\GetClinicalPlanHandler;
use App\Domains\Clinical\Application\Query\GetDiagnosis;
use App\Domains\Clinical\Application\Query\GetDiagnosisHandler;
use App\Domains\Clinical\Application\Query\GetEncounterCareTeam;
use App\Domains\Clinical\Application\Query\GetEncounterCareTeamHandler;
use App\Domains\Clinical\Application\Query\GetEncounterProcedures;
use App\Domains\Clinical\Application\Query\GetEncounterProceduresHandler;
use App\Domains\Clinical\Application\Query\GetEncounterTriageAssessments;
use App\Domains\Clinical\Application\Query\GetEncounterTriageAssessmentsHandler;
use App\Domains\Clinical\Application\Query\GetPatientAllergies;
use App\Domains\Clinical\Application\Query\GetPatientAllergiesHandler;
use App\Domains\Clinical\Application\Query\GetPatientClinicalPlans;
use App\Domains\Clinical\Application\Query\GetPatientClinicalPlansHandler;
use App\Domains\Clinical\Application\Query\GetPatientPrescribingDecisions;
use App\Domains\Clinical\Application\Query\GetPatientPrescribingDecisionsHandler;
use App\Domains\Clinical\Application\Query\GetPatientProblems;
use App\Domains\Clinical\Application\Query\GetPatientProblemsHandler;
use App\Domains\Clinical\Application\Query\GetPatientProcedures;
use App\Domains\Clinical\Application\Query\GetPatientProceduresHandler;
use App\Domains\Clinical\Application\Query\GetPatientVitalSigns;
use App\Domains\Clinical\Application\Query\GetPatientVitalSignsHandler;
use App\Domains\Clinical\Application\Query\GetPrescribingDecision;
use App\Domains\Clinical\Application\Query\GetPrescribingDecisionHandler;
use App\Domains\Clinical\Application\Query\GetProblem;
use App\Domains\Clinical\Application\Query\GetProblemHandler;
use App\Domains\Clinical\Application\Query\GetProcedure;
use App\Domains\Clinical\Application\Query\GetProcedureDefinition;
use App\Domains\Clinical\Application\Query\GetProcedureDefinitionHandler;
use App\Domains\Clinical\Application\Query\GetProcedureDefinitions;
use App\Domains\Clinical\Application\Query\GetProcedureDefinitionsHandler;
use App\Domains\Clinical\Application\Query\GetProcedureHandler;
use App\Domains\Clinical\Application\Query\GetTriageAssessment;
use App\Domains\Clinical\Application\Query\GetTriageAssessmentHandler;
use App\Domains\Clinical\Application\Query\GetVitalSignsObservation;
use App\Domains\Clinical\Application\Query\GetVitalSignsObservationHandler;
use App\Domains\Clinical\Authorization\AllergyPolicy;
use App\Domains\Clinical\Authorization\CareTeamMembershipPolicy;
use App\Domains\Clinical\Authorization\ClinicalNotePolicy;
use App\Domains\Clinical\Authorization\ClinicalPlanPolicy;
use App\Domains\Clinical\Authorization\DiagnosisPolicy;
use App\Domains\Clinical\Authorization\PrescribingDecisionPolicy;
use App\Domains\Clinical\Authorization\ProblemPolicy;
use App\Domains\Clinical\Authorization\ProcedurePolicy;
use App\Domains\Clinical\Authorization\TriageAssessmentPolicy;
use App\Domains\Clinical\Authorization\VitalSignsObservationPolicy;
use App\Domains\Clinical\Console\DefineProcedureCommand;
use App\Domains\Clinical\Contracts\ClinicalApi;
use App\Domains\Clinical\Domain\AllergyReader;
use App\Domains\Clinical\Domain\AllergyRepository;
use App\Domains\Clinical\Domain\CareTeamMembershipReader;
use App\Domains\Clinical\Domain\CareTeamMembershipRepository;
use App\Domains\Clinical\Domain\ClinicalNoteReader;
use App\Domains\Clinical\Domain\ClinicalNoteRepository;
use App\Domains\Clinical\Domain\ClinicalPlanReader;
use App\Domains\Clinical\Domain\ClinicalPlanRepository;
use App\Domains\Clinical\Domain\ClinicalProcedureReader;
use App\Domains\Clinical\Domain\ClinicalProcedureRepository;
use App\Domains\Clinical\Domain\DiagnosisReader;
use App\Domains\Clinical\Domain\DiagnosisRepository;
use App\Domains\Clinical\Domain\Model\Allergy;
use App\Domains\Clinical\Domain\Model\CareTeamMembership;
use App\Domains\Clinical\Domain\Model\ClinicalNote;
use App\Domains\Clinical\Domain\Model\ClinicalPlan;
use App\Domains\Clinical\Domain\Model\ClinicalProcedure;
use App\Domains\Clinical\Domain\Model\Diagnosis;
use App\Domains\Clinical\Domain\Model\PrescribingDecision;
use App\Domains\Clinical\Domain\Model\Problem;
use App\Domains\Clinical\Domain\Model\TriageAssessment;
use App\Domains\Clinical\Domain\Model\VitalSignsObservation;
use App\Domains\Clinical\Domain\PrescribingDecisionReader;
use App\Domains\Clinical\Domain\PrescribingDecisionRepository;
use App\Domains\Clinical\Domain\ProblemReader;
use App\Domains\Clinical\Domain\ProblemRepository;
use App\Domains\Clinical\Domain\ProcedureDefinitionReader;
use App\Domains\Clinical\Domain\ProcedureDefinitionRepository;
use App\Domains\Clinical\Domain\ProcedureProtocolApprovalReader;
use App\Domains\Clinical\Domain\TriageAssessmentReader;
use App\Domains\Clinical\Domain\TriageAssessmentRepository;
use App\Domains\Clinical\Domain\VitalSignsObservationReader;
use App\Domains\Clinical\Domain\VitalSignsObservationRepository;
use App\Domains\Clinical\Infrastructure\Reader\EloquentAllergyReader;
use App\Domains\Clinical\Infrastructure\Reader\EloquentCareTeamMembershipReader;
use App\Domains\Clinical\Infrastructure\Reader\EloquentClinicalNoteReader;
use App\Domains\Clinical\Infrastructure\Reader\EloquentClinicalPlanReader;
use App\Domains\Clinical\Infrastructure\Reader\EloquentClinicalProcedureReader;
use App\Domains\Clinical\Infrastructure\Reader\EloquentDiagnosisReader;
use App\Domains\Clinical\Infrastructure\Reader\EloquentPrescribingDecisionReader;
use App\Domains\Clinical\Infrastructure\Reader\EloquentProblemReader;
use App\Domains\Clinical\Infrastructure\Reader\EloquentProcedureDefinitionReader;
use App\Domains\Clinical\Infrastructure\Reader\EloquentProcedureProtocolApprovalReader;
use App\Domains\Clinical\Infrastructure\Reader\EloquentTriageAssessmentReader;
use App\Domains\Clinical\Infrastructure\Reader\EloquentVitalSignsObservationReader;
use App\Domains\Clinical\Infrastructure\Repository\EloquentAllergyRepository;
use App\Domains\Clinical\Infrastructure\Repository\EloquentCareTeamMembershipRepository;
use App\Domains\Clinical\Infrastructure\Repository\EloquentClinicalNoteRepository;
use App\Domains\Clinical\Infrastructure\Repository\EloquentClinicalPlanRepository;
use App\Domains\Clinical\Infrastructure\Repository\EloquentClinicalProcedureRepository;
use App\Domains\Clinical\Infrastructure\Repository\EloquentDiagnosisRepository;
use App\Domains\Clinical\Infrastructure\Repository\EloquentPrescribingDecisionRepository;
use App\Domains\Clinical\Infrastructure\Repository\EloquentProblemRepository;
use App\Domains\Clinical\Infrastructure\Repository\EloquentProcedureDefinitionRepository;
use App\Domains\Clinical\Infrastructure\Repository\EloquentTriageAssessmentRepository;
use App\Domains\Clinical\Infrastructure\Repository\EloquentVitalSignsObservationRepository;
use App\Shared\Authorization\PermissionRegistry;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Clinical module (docs/01-domains/12-clinical-domain.md; ADR-131 … ADR-137;
 * ADR-146 reconciles ADR-137's placement for CP.1), prefix `clin_`.
 *
 * PHASE 6 is backend / domain contract only (ADR-131, ADR-124 two-gate
 * reading): no Presentation/Http, no routes.php, no Clinical workspace, no
 * `clin_rm_*` composed read model — those are PHASE 16.
 *
 * Increment C.1: the `ClinicalNote` aggregate + `ClinicalNoteAmendment` child +
 * the four lifecycle commands (`CreateClinicalNote` → `DRAFT`;
 * `SignClinicalNote` `DRAFT → SIGNED`; `CancelClinicalNote` `DRAFT → CANCELLED`
 * — a pure transition, no event, not audited; `AmendClinicalNote`
 * `SIGNED → SIGNED` appending an append-only `clin_note_amendments` row) +
 * `GetClinicalNote` (`#[ProtectedQuery]`), through a `ClinicalNoteLifecycle`
 * domain service. `ClinicalNoteStatus` / `ClinicalNoteAmendmentType` enums +
 * `ClinicalNoteType` value object (open coded, behaviour-neutral — ADR-134).
 * Three events (`ClinicalNoteCreated`, `ClinicalNoteSigned`,
 * `ClinicalNoteAmended` — PHI-free, ADR-136); three audit actions
 * (`clinical.note_created`, `clinical.note_signed`, `clinical.note_amended`).
 * `clinical.access` + `clinical.notes.{read,create,sign,amend}` keys (ADR-133).
 * The `ClinicalApi` port (`clinicalNoteExists`). `clin_notes` +
 * `clin_note_amendments` migrations. Cross-domain: `PatientApi::getPatientSummary`
 * + the E.13 `EncounterApi::getEncounterContext` (ADR-135), consumed only by
 * `CreateClinicalNoteHandler`.
 *
 * Increment C.2 (ADR-138): the `VitalSignsObservation` aggregate (one vitals
 * set, nine nullable typed measurement columns, `clin_vital_sign_observations`)
 * + `VitalSigns` value object (fixed canonical units, structural safety bounds,
 * at-least-one rule) + `RecordVitalSigns` (single immutable `RECORDED` state) +
 * `CorrectVitalSignsObservation` (superseding-observation correction — a new row
 * carrying `supersedes_observation_id` + a mandatory `reason`, a one-time stamp
 * on the original; NO void command) + `GetVitalSignsObservation`
 * (`#[ProtectedQuery]`, by-id only — no `clin_rm_*`, no `ClinicalApi` method).
 * Two events (`VitalSignsRecorded`, `VitalSignsObservationCorrected` — PHI-free,
 * ADR-136); two audit actions (`clinical.vitals.recorded`,
 * `clinical.vitals.corrected` — no measurement values). `clinical.vitals.{read,
 * record}` keys (correction uses `record`). Cross-domain (origination only):
 * `PatientApi::getPatientSummary` + `EncounterApi::getEncounterContext`.
 *
 * Increment C.3 (ADR-139): the `Diagnosis` aggregate (one encounter-bound
 * diagnosis assertion, `clin_diagnoses`) + `DiagnosisCode` value object
 * (optional open `code` + conditional open `code_system`; no enum / terminology
 * validation) + `RecordDiagnosis` (single immutable `RECORDED` state; required
 * `description`; open behaviour-neutral `clinical_status`, default `WORKING`;
 * `authored_context` hardcoded `CLINICIAN`) + `CorrectDiagnosis`
 * (superseding-diagnosis correction — a new row carrying `supersedes_diagnosis_id`
 * + a mandatory clinical `reason`, a one-time stamp on the original; NO
 * void / Update / Resolve command) + `GetDiagnosis` (`#[ProtectedQuery]`, by-id
 * only — no `clin_rm_*`, no `ClinicalApi` method). Two events
 * (`DiagnosisRecorded` — carries `diagnosisCodeSystem` + `clinicalStatus`, never
 * the code or description; `DiagnosisCorrected` — PHI-free, ADR-136); two audit
 * actions (`clinical.diagnoses.recorded`, `clinical.diagnoses.corrected` — no
 * code / description / rationale). `clinical.diagnoses.{read,record}` keys
 * (correction uses `record`). Cross-domain (origination only):
 * `PatientApi::getPatientSummary` + `EncounterApi::getEncounterContext`.
 *
 * Increment C.4 (ADR-140): the `Problem` aggregate (one encounter-originated
 * longitudinal problem-list entry, `clin_problems`) + `ProblemCode` value
 * object (optional open `code` + conditional open `code_system`, MUTABLE) +
 * `ProblemHistoryEntry` append-only pre-change snapshots (`clin_problem_history`)
 * + `AddProblem` (ACTIVE; `authored_context` hardcoded `CLINICIAN`; optional
 * `sourceDiagnosisId` provenance validated read-only via `DiagnosisReader` — no
 * Diagnosis mutation) + `UpdateProblem` (status-agnostic in-place revision /
 * correction of `description` / `code` / `code_system` / `onset_date`;
 * `REVISION` | `CORRECTION`; pre-change history row; NO `CorrectProblem`) +
 * `ResolveProblem` (`ACTIVE → RESOLVED` only; set-once `resolved_at`; NO
 * reactivation) + `GetProblem` + `GetPatientProblems` (`#[ProtectedQuery]`,
 * originating-facility-scoped, no pagination — no `clin_rm_*`, no `ClinicalApi`
 * method). Three events (`ProblemAdded` — carries `problemCodeSystem`;
 * `ProblemUpdated` — carries `changeType`; `ProblemResolved` — carries
 * `resolvedAt`; all PHI-free, ADR-136); three audit actions
 * (`clinical.problems.{added,updated,resolved}` — `metadata = []`, no
 * description / code / onset / rationale). `clinical.problems.{read,record}`
 * keys (`record` covers Add / Update / Resolve). Cross-domain (origination
 * only): `PatientApi::getPatientSummary` + `EncounterApi::getEncounterContext`;
 * intra-domain read-only `DiagnosisReader::summary()` for provenance.
 *
 * Increment C.5 (ADR-141): the `Allergy` aggregate (one encounter-originated
 * longitudinal allergy assertion, `clin_allergies`) + `AllergySubstance`
 * value object (required trimmed `name` + optional open `code` / `code_system`;
 * no terminology engine) + `AllergyHistoryEntry` append-only pre-change
 * snapshots (`clin_allergy_history`) + two-axis status model (`clinical_status`:
 * ACTIVE | RESOLVED | INACTIVE; `verification_status`: SUSPECTED | CONFIRMED |
 * REFUTED; bound by `REFUTED <=> INACTIVE`) + inline reaction (`reaction_description`,
 * `severity`) + `RecordAllergy` (ACTIVE; SUSPECTED | CONFIRMED; `authored_context`
 * CLINICIAN | NURSING; cross-domain `PatientApi` + `EncounterApi`) +
 * `UpdateAllergy` (content-only mutation; REVISION | CORRECTION with pre-change
 * history row) + `VerifyAllergy` (CONFIRMED | REFUTED with pre-change history
 * row; REFUTED drives INACTIVE) + `ResolveAllergy` (ACTIVE -> RESOLVED only;
 * set-once `resolved_at`; NO history row) + `GetAllergy` + `GetPatientAllergies`
 * (`#[ProtectedQuery]`, TENANT-WIDE read exception per Q7 / ADR-141 D-M — no
 * originating-facility refinement on reads; NO `ClinicalApi` method). Four
 * events (`AllergyRecorded`, `AllergyUpdated`, `AllergyVerified`, `AllergyResolved`
 * — all PHI-free, ADR-136); four audit actions (`clinical.allergies.{recorded,
 * updated,verified,resolved}` — `metadata = []`, backdate reason on audit only).
 * `clinical.allergies.{read,record}` keys (`record` covers Record / Update /
 * Verify / Resolve; writes are originating-facility-refined).
 *
 * Increment C.6 (ADR-142): the `CareTeamMembership` aggregate (one staff member
 * holding one role on one encounter for one bounded period,
 * `clin_care_team_memberships`) + `TeamRole` value object (open coded string,
 * set-validated, not `#[TypeScript]`) — NO `CareTeam` aggregate root, NO
 * pre-change history table (the retained ended rows + the event / audit stream
 * are the history) + `is_provider_of_record` flag bound by a DB partial unique
 * index (`<= 1` active provider-of-record per encounter; a second partial unique
 * index forbids a simultaneous active duplicate `(encounter, staff, role)`
 * membership) + `AddCareTeamMember` (ACTIVE; optional first provider-of-record;
 * cross-domain `PatientApi` + `EncounterApi` + `IdentityApi::isStaff`;
 * encounter-state gate {PLANNED, ARRIVED, ACTIVE, COMPLETED} — PLANNED
 * permitted, ADR-135 unchanged) + `EndCareTeamMembership` (`ACTIVE -> ENDED`
 * terminal; optional `end_reason` on the row only; clears provider-of-record if
 * set) + `ChangeProviderOfRecord` (atomic two-instance hand-off; NO `reason`
 * parameter — reconciliation R-1) + `GetEncounterCareTeam` (`includeEnded`) +
 * `GetCareTeamMembership` (`#[ProtectedQuery]`, originating-facility-scoped
 * reads AND writes — NOT the ADR-141 D-M tenant-wide exception; no
 * `ClinicalApi` method). Three events (`CareTeamMemberAdded`,
 * `CareTeamMemberRemoved`, `ProviderOfRecordChanged` — all PHI-free, ADR-136;
 * a command may emit two); three audit actions
 * (`clinical.care_team.{member_added,member_removed,provider_of_record_changed}`
 * — `metadata = []`, `reason = null` always). `clinical.care_team.{read,record}`
 * keys (`record` covers all three commands; care-team membership is NOT an
 * authorization input). Cross-domain (origination only):
 * `PatientApi::getPatientSummary` + `EncounterApi::getEncounterContext` +
 * `IdentityApi::isStaff`.
 *
 * Increment C.7 (ADR-143): `ClinicalPlan` aggregate (`clin_plans`) + `ClinicalPlanHistoryEntry`
 * append-only snapshots (`clin_plan_history`) + `PrescribingDecision` aggregate
 * (`clin_prescribing_decisions`).
 * `ClinicalPlan`: encounter-originated longitudinal treatment / management plan (ACTIVE |
 * COMPLETED | DISCONTINUED), bounded mutation with pre-change history row, free-text clinical
 * facets (intent, goals, planned actions, instructions, follow-up), optional indication
 * provenance. Commands: `CreateClinicalPlan`, `UpdateClinicalPlan`, `CloseClinicalPlan`.
 * `PrescribingDecision`: encounter-originated therapeutic prescribing decision (ACTIVE |
 * SUPERSEDED | DISCONTINUED), immutable recorded + superseding-record correction + clinical-stop
 * discontinuation, full dosing intent. Commands: `RecordPrescribingDecision`,
 * `CorrectPrescribingDecision`, `DiscontinuePrescribingDecision`.
 * Events: `ClinicalPlanCreated`, `ClinicalPlanUpdated`, `PrescriptionIssued` (all PHI-minimized,
 * deny-listed per ADR-136). Reads: `GetClinicalPlan`, `GetPatientClinicalPlans`,
 * `GetPrescribingDecision`, `GetPatientPrescribingDecisions` (all protected, originating-facility
 * refined). Policies: `ClinicalPlanPolicy`, `PrescribingDecisionPolicy`. Permissions:
 * `clinical.plans.{read,record}`, `clinical.prescribing.{read,record}`.
 *
 * Increment C.9 (ADR-145): the `TriageAssessment` aggregate (one rapid
 * point-in-time structured triage record, `clin_triage_assessments`; NOT a
 * `ClinicalNote note_type=triage`) + `TriagePriority` value object (CLOSED coded
 * set `EMERGENCY | URGENT | HIGH | NORMAL | LOW`, `final class`, not
 * `#[TypeScript]` — the one behaviour-carrying field, which Queueing & Patient
 * Flow consumes in PHASE 12; ADR-105 — Clinical determines it, Queueing never
 * recomputes it). Required `chief_complaint` (free text, never in event /
 * audit) + open-coded `acuity` / `consciousness` (documentation only, no scale
 * enum) + optional read-only `source_vitals_observation_id` (a C.2 provenance
 * pointer, FK-free — the ADR-140 D-I precedent) + superseding metadata
 * (intra-domain self-FK). Encounter-scoped, single immutable `RECORDED` state;
 * `RecordTriageAssessment` + `CorrectTriageAssessment` (re-triage / correction =
 * a new superseding record + mandatory `reason`, a one-time stamp on the
 * original; NO Update / Cancel / Void command). `GetTriageAssessment`
 * (`#[ProtectedQuery]`, by id) + `GetEncounterTriageAssessments` (domain reader,
 * `?currentOnly`, originating-facility-scoped, NO pagination — no `clin_rm_*`,
 * no `ClinicalApi` method). Two PHI-minimal consumer-less events
 * (`TriageAssessmentRecorded`, `TriageAssessmentCorrected` — identifiers + coded
 * `triagePriority` only; ADR-136); two audit actions
 * (`clinical.triage.{recorded,corrected}` — `metadata = []`, no chief complaint
 * / acuity / consciousness / rationale). `clinical.triage.{read,record}` keys
 * (`record` covers Record / Correct). `{CLINICIAN, NURSING}` `authored_context`
 * (the ADR-144 mechanism — not an authorization input). Cross-domain
 * (origination only): `PatientApi::getPatientSummary` +
 * `EncounterApi::getEncounterContext`; intra-domain read-only
 * `VitalSignsObservationReader::summary()` for the optional vitals provenance
 * check. ADR-135 gates reused (patient `ACTIVE` incl. provisional; encounter
 * status in {ARRIVED, ACTIVE, COMPLETED} — PLANNED rejected, ADR-135 unchanged).
 * Originating-facility-scoped reads AND writes; tenant FORCE RLS, no facility
 * predicate, no history table. NO C.2 / Queueing / `ClinicalApi` / `generated.ts`
 * change.
 *
 * Increment CP.1 (ADR-146, reconciling the ADR-137 frame): the LAST PHASE 6
 * increment — two aggregates, FLAT under `app/Domains/Clinical/` (ADR-146 D-A —
 * ADR-137's `Procedures/` sub-path is a logical sub-area of this one module, not
 * a physical subdirectory).
 * `ProcedureDefinition` (`clin_procedure_definitions`) — tenant-scoped, mutable,
 * integer-versioned catalog reference data (NOT the ADR-132 clinical-record
 * shape). Authored ONLY via the `clinical:define-procedure` console command +
 * `Database\Seeders\TenantProcedureDefinitionSeeder` — NO HTTP command, NO
 * `clinical.procedures.manage_catalog` key (ADR-146 D-C).
 * `ClinicalProcedure` (`clin_procedures`) — the encounter-originated execution
 * record: `PROC-%08d` (`clin_procedure_number_counters`, the ADR-115 pattern) +
 * `definition_id` (the one real FK) + `definition_version` / `procedure_category`
 * snapshots + `ClinicalProcedureStatus` (`#[TypeScript]` — the first
 * `generated.ts` growth since C.1) lifecycle `REQUESTED → AUTHORIZED →
 * [READY] → IN_PROGRESS → COMPLETED` via `ClinicalProcedureLifecycle`
 * (`AUTHORIZED` always explicit; `READY` entered only when the definition
 * requires a readiness check; terminal `CANCELLED` / `ABORTED` / `DECLINED`
 * from `{AUTHORIZED, READY}` — an ADR-137 refinement, ADR-146 D-F) + open `site`
 * / `outcome` + closed `ProcedureLaterality` (`final class`, not `#[TypeScript]`)
 * + free-text `outcome_detail` / `complications` / `status_reason` / `indication`
 * / `location_label` (row only, never event / audit) + plain-uuid FK-free
 * read-only provenance `source_diagnosis_id` (C.3) / `source_clinical_plan_id`
 * (C.7) / `clinical_note_id` (ADR-140 D-I pattern) + pass-through
 * `financial_clearance_ref` / `financial_policy_version`. ADR-135 origination
 * gates reused verbatim at `RequestProcedure` + `FacilityCapabilities::has`
 * (ADR-120); later commands NOT encounter-state-gated EXCEPT `AddProcedureNote`
 * (dispatches the UNCHANGED C.1 `CreateClinicalNote` with `note_type =
 * 'procedure_note'` — NO change to the C.1 write path, NO ADR-134/144
 * amendment — the actor also needs `clinical.notes.create`). Record-field
 * corrections via `AmendProcedure` append an append-only
 * `clin_procedure_amendments` row (the ADR-134 model ADR-137 named) while
 * applying the corrected values; `RecordProcedureOutcome` only while
 * `IN_PROGRESS` (S3). `RecordProcedureMaterials` → append-only
 * `clin_procedure_materials` → PHI-free `ProcedureMaterialsConsumed` (Inventory
 * reacts in PHASE 9). Twelve commands (`RequestProcedure`, `AuthorizeProcedure`,
 * `MarkProcedureReady`, `StartProcedure`, `CompleteProcedure`, `CancelProcedure`,
 * `AbortProcedure`, `DeclineProcedure`, `RecordProcedureOutcome`,
 * `AddProcedureNote`, `RecordProcedureMaterials`, `AmendProcedure`), five
 * queries (`GetProcedure`, `GetProcedureDefinition`, `GetProcedureDefinitions`,
 * `GetEncounterProcedures`, `GetPatientProcedures` — all `#[ProtectedQuery]`, no
 * `clin_rm_*` / search / queue). ADR-137's nine PHI-minimal consumer-less events
 * (`procedureId` only — no `procedure_number`; `ProcedureCompleted` carries the
 * coded `outcome`, no `laterality`); ~14 audit actions. FIVE keys
 * `clinical.procedures.{read,request,perform,complete,amend}` — finer than the
 * C.3–C.9 `{read,record}` collapse (a multi-actor workflow); `complete` alone is
 * the Billing trigger. `ProcedurePolicy`: `request`/`view` class-level;
 * `authorize`/`markReady`/`cancel`/`decline` (`request` key),
 * `start`/`abort`/`recordOutcome`/`addNote`/`recordMaterials` (`perform` key),
 * `complete` (`complete` key), `amend` (`amend` key) per-subject at the
 * procedure's own facility. NO `ClinicalApi` method, NO frontend beyond the one
 * enum. ADR-131…ADR-145 unchanged; C.1–C.9 untouched and closed.
 */
final class ClinicalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ClinicalNoteRepository::class, EloquentClinicalNoteRepository::class);
        $this->app->bind(ClinicalNoteReader::class, EloquentClinicalNoteReader::class);
        $this->app->bind(ClinicalApi::class, ClinicalApiService::class);

        $this->app->bind(VitalSignsObservationRepository::class, EloquentVitalSignsObservationRepository::class);
        $this->app->bind(VitalSignsObservationReader::class, EloquentVitalSignsObservationReader::class);

        $this->app->bind(DiagnosisRepository::class, EloquentDiagnosisRepository::class);
        $this->app->bind(DiagnosisReader::class, EloquentDiagnosisReader::class);

        $this->app->bind(ProblemRepository::class, EloquentProblemRepository::class);
        $this->app->bind(ProblemReader::class, EloquentProblemReader::class);

        $this->app->bind(AllergyRepository::class, EloquentAllergyRepository::class);
        $this->app->bind(AllergyReader::class, EloquentAllergyReader::class);

        $this->app->bind(CareTeamMembershipRepository::class, EloquentCareTeamMembershipRepository::class);
        $this->app->bind(CareTeamMembershipReader::class, EloquentCareTeamMembershipReader::class);

        $this->app->bind(ClinicalPlanRepository::class, EloquentClinicalPlanRepository::class);
        $this->app->bind(ClinicalPlanReader::class, EloquentClinicalPlanReader::class);

        $this->app->bind(PrescribingDecisionRepository::class, EloquentPrescribingDecisionRepository::class);
        $this->app->bind(PrescribingDecisionReader::class, EloquentPrescribingDecisionReader::class);

        $this->app->bind(TriageAssessmentRepository::class, EloquentTriageAssessmentRepository::class);
        $this->app->bind(TriageAssessmentReader::class, EloquentTriageAssessmentReader::class);

        $this->app->bind(ProcedureDefinitionRepository::class, EloquentProcedureDefinitionRepository::class);
        $this->app->bind(ProcedureDefinitionReader::class, EloquentProcedureDefinitionReader::class);
        $this->app->bind(ProcedureProtocolApprovalReader::class, EloquentProcedureProtocolApprovalReader::class);
        $this->app->bind(ClinicalProcedureRepository::class, EloquentClinicalProcedureRepository::class);
        $this->app->bind(ClinicalProcedureReader::class, EloquentClinicalProcedureReader::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        PermissionRegistry::add(require __DIR__.'/Authorization/permissions.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                DefineProcedureCommand::class,
                Console\SyncClinicalRolePermissionsCommand::class,
            ]);
        }

        CommandBus::map([
            CreateClinicalNote::class => CreateClinicalNoteHandler::class,
            SignClinicalNote::class => SignClinicalNoteHandler::class,
            CancelClinicalNote::class => CancelClinicalNoteHandler::class,
            AmendClinicalNote::class => AmendClinicalNoteHandler::class,
            RecordVitalSigns::class => RecordVitalSignsHandler::class,
            CorrectVitalSignsObservation::class => CorrectVitalSignsObservationHandler::class,
            RecordDiagnosis::class => RecordDiagnosisHandler::class,
            CorrectDiagnosis::class => CorrectDiagnosisHandler::class,
            AddProblem::class => AddProblemHandler::class,
            UpdateProblem::class => UpdateProblemHandler::class,
            ResolveProblem::class => ResolveProblemHandler::class,
            RecordAllergy::class => RecordAllergyHandler::class,
            UpdateAllergy::class => UpdateAllergyHandler::class,
            VerifyAllergy::class => VerifyAllergyHandler::class,
            ResolveAllergy::class => ResolveAllergyHandler::class,
            AddCareTeamMember::class => AddCareTeamMemberHandler::class,
            EndCareTeamMembership::class => EndCareTeamMembershipHandler::class,
            ChangeProviderOfRecord::class => ChangeProviderOfRecordHandler::class,
            CreateClinicalPlan::class => CreateClinicalPlanHandler::class,
            UpdateClinicalPlan::class => UpdateClinicalPlanHandler::class,
            CloseClinicalPlan::class => CloseClinicalPlanHandler::class,
            RecordPrescribingDecision::class => RecordPrescribingDecisionHandler::class,
            CorrectPrescribingDecision::class => CorrectPrescribingDecisionHandler::class,
            DiscontinuePrescribingDecision::class => DiscontinuePrescribingDecisionHandler::class,
            RecordTriageAssessment::class => RecordTriageAssessmentHandler::class,
            CorrectTriageAssessment::class => CorrectTriageAssessmentHandler::class,
            RequestProcedure::class => RequestProcedureHandler::class,
            AuthorizeProcedure::class => AuthorizeProcedureHandler::class,
            MarkProcedureReady::class => MarkProcedureReadyHandler::class,
            StartProcedure::class => StartProcedureHandler::class,
            CompleteProcedure::class => CompleteProcedureHandler::class,
            CancelProcedure::class => CancelProcedureHandler::class,
            AbortProcedure::class => AbortProcedureHandler::class,
            DeclineProcedure::class => DeclineProcedureHandler::class,
            RecordProcedureOutcome::class => RecordProcedureOutcomeHandler::class,
            AddProcedureNote::class => AddProcedureNoteHandler::class,
            RecordProcedureMaterials::class => RecordProcedureMaterialsHandler::class,
            AmendProcedure::class => AmendProcedureHandler::class,
        ]);

        QueryBus::map([
            GetClinicalNote::class => GetClinicalNoteHandler::class,
            GetVitalSignsObservation::class => GetVitalSignsObservationHandler::class,
            GetPatientVitalSigns::class => GetPatientVitalSignsHandler::class,
            GetDiagnosis::class => GetDiagnosisHandler::class,
            GetProblem::class => GetProblemHandler::class,
            GetPatientProblems::class => GetPatientProblemsHandler::class,
            GetAllergy::class => GetAllergyHandler::class,
            GetPatientAllergies::class => GetPatientAllergiesHandler::class,
            GetEncounterCareTeam::class => GetEncounterCareTeamHandler::class,
            GetCareTeamMembership::class => GetCareTeamMembershipHandler::class,
            GetClinicalPlan::class => GetClinicalPlanHandler::class,
            GetPatientClinicalPlans::class => GetPatientClinicalPlansHandler::class,
            GetPrescribingDecision::class => GetPrescribingDecisionHandler::class,
            GetPatientPrescribingDecisions::class => GetPatientPrescribingDecisionsHandler::class,
            GetTriageAssessment::class => GetTriageAssessmentHandler::class,
            GetEncounterTriageAssessments::class => GetEncounterTriageAssessmentsHandler::class,
            GetProcedure::class => GetProcedureHandler::class,
            GetProcedureDefinition::class => GetProcedureDefinitionHandler::class,
            GetProcedureDefinitions::class => GetProcedureDefinitionsHandler::class,
            GetEncounterProcedures::class => GetEncounterProceduresHandler::class,
            GetPatientProcedures::class => GetPatientProceduresHandler::class,
        ]);

        Gate::policy(ClinicalNote::class, ClinicalNotePolicy::class);
        Gate::policy(VitalSignsObservation::class, VitalSignsObservationPolicy::class);
        Gate::policy(Diagnosis::class, DiagnosisPolicy::class);
        Gate::policy(Problem::class, ProblemPolicy::class);
        Gate::policy(Allergy::class, AllergyPolicy::class);
        Gate::policy(CareTeamMembership::class, CareTeamMembershipPolicy::class);
        Gate::policy(ClinicalPlan::class, ClinicalPlanPolicy::class);
        Gate::policy(PrescribingDecision::class, PrescribingDecisionPolicy::class);
        Gate::policy(TriageAssessment::class, TriageAssessmentPolicy::class);
        Gate::policy(ClinicalProcedure::class, ProcedurePolicy::class);
    }
}
