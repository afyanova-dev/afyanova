<?php

declare(strict_types=1);

/**
 * Clinical permission keys (docs/00-foundation/03-identity-rbac.md §31–33, §66;
 * 12-clinical-domain.md §87; ADR-133). L1 `.access` + L2 `{resource}.{action}`,
 * lower snake per IC §3/§12 + ADR-122 KEY CASING. Aggregated into
 * PermissionRegistry by ClinicalServiceProvider::boot().
 *
 * ADR-133 registers exactly the C.1 (`ClinicalNote`) keys at C.1; later
 * increments add their own keys as their commands land. NO `clinical.notes.cancel`
 * (`CancelClinicalNote` is gated by `clinical.notes.amend` — ADR-133: `12` §87
 * lists create / update / sign / amend, not cancel). NO `clinical.diagnoses.manage`
 * — ADR-139 D9 ratifies the concrete C.3 keys as `clinical.diagnoses.{read,record}`
 * (correction uses `record`), superseding the illustrative `manage` label in
 * ADR-133's forward vocabulary list. NO `clinical.problems.manage` / `.update` /
 * `.correct` / `.resolve` / `.remove` / `.promote` / `.amend` — ADR-140 D-K
 * ratifies the concrete C.4 keys as `clinical.problems.{read,record}` (`record`
 * covers Add / Update / Resolve), superseding the illustrative
 * `clinical.problems.manage` label in `12` §87 / ADR-133's forward vocabulary.
 * NO `clinical.allergies.manage` / `.update` / `.verify` / `.resolve` /
 * `.correct` / `.retract` — ADR-141 D-L ratifies the concrete C.5 keys as
 * `clinical.allergies.{read,record}` (`record` covers Record / Update / Verify /
 * Resolve), superseding the illustrative `clinical.allergies.manage` label.
 * NO `clinical.care_team.manage` / `.assign` / `.remove` / `.change_provider` —
 * ADR-142 D-N ratifies the concrete C.6 keys as `clinical.care_team.{read,record}`
 * (`record` covers AddCareTeamMember / EndCareTeamMembership /
 * ChangeProviderOfRecord), superseding the illustrative `clinical.care_team.manage`
 * label in `12` §87 / ADR-133's forward vocabulary. Care-team membership is NOT
 * itself an authorization input (ADR-133 / `12` §159; ADR-142 D-N).
 * NO `clinical.triage.manage` / `.create` / `.correct` / `.update` — ADR-145 D-L
 * ratifies the concrete C.9 keys as `clinical.triage.{read,record}` (`record`
 * covers RecordTriageAssessment + CorrectTriageAssessment — a correction /
 * re-triage is a new record). The triage priority is NOT an authorization input
 * for anything (ADR-145 D-L); `authored_context` is NOT an authorization input
 * (the ADR-144 mechanism).
 * NO `clinical.procedures.manage_catalog` / `.manage` / `.schedule` / `.create`
 * / `.update` / `.correct` — ADR-146 D-O ratifies FIVE concrete CP.1 keys
 * `clinical.procedures.{read, request, perform, complete, amend}` — deliberately
 * FINER than the C.3–C.9 `{read, record}` collapse because a procedure is a
 * multi-actor WORKFLOW (13 §21, §141), not a single-actor documentation act:
 * `request` covers RequestProcedure / AuthorizeProcedure / MarkProcedureReady /
 * CancelProcedure / DeclineProcedure; `perform` covers StartProcedure /
 * RecordProcedureOutcome / RecordProcedureMaterials / AddProcedureNote /
 * AbortProcedure; `complete` covers ONLY CompleteProcedure (the Billing-
 * triggering terminal fact, gated separately so an organisation may restrict
 * "who declares this done and billable"); `amend` covers ONLY AmendProcedure
 * (the privileged post-completion correction — the `clinical.notes.amend`
 * precedent). Catalog authoring (`clinical:define-procedure`) is CONSOLE-ONLY
 * in CP.1 — NO permission key at all (ADR-146 D-C). `AddProcedureNote`
 * ADDITIONALLY requires `clinical.notes.create` (it dispatches the UNCHANGED
 * C.1 `CreateClinicalNote` — ADR-146 D-L).
 *
 * @return list<string>
 */
return [
    'clinical.access',
    'clinical.documentation_status.read', // Recorded/not-recorded indicators only; no clinical values.
    'clinical.notes.read',       // GetClinicalNote (C.1) — protected by-id read; ADR-133 / IC §6.2
    'clinical.notes.create',     // CreateClinicalNote (C.1) — originate a DRAFT; ADR-133 / ADR-134
    'clinical.notes.sign',       // SignClinicalNote (C.1) — attest a note (DRAFT -> SIGNED); ADR-133 / ADR-134
    'clinical.notes.amend',      // AmendClinicalNote (C.1) + CancelClinicalNote (C.1) — privileged correction key; ADR-133 / ADR-134
    'clinical.vitals.read',      // GetVitalSignsObservation (C.2) — protected by-id read; ADR-133 / ADR-138 D16
    'clinical.vitals.record',    // RecordVitalSigns (C.2) + CorrectVitalSignsObservation (C.2) — a correction is a new record; ADR-138 D16
    'clinical.diagnoses.read',   // GetDiagnosis (C.3) — protected by-id read; ADR-133 / ADR-139 D9
    'clinical.diagnoses.record', // RecordDiagnosis (C.3) + CorrectDiagnosis (C.3) — a correction is a new record; ADR-139 D9
    'clinical.problems.read',    // GetProblem + GetPatientProblems (C.4) — protected reads; ADR-133 / ADR-140 D-K / D-L
    'clinical.problems.record',  // AddProblem + UpdateProblem + ResolveProblem (C.4) — one key covers all three; ADR-140 D-K
    'clinical.allergies.read',   // GetAllergy + GetPatientAllergies (C.5) — protected TENANT-WIDE reads (ADR-141 D-M); ADR-133 / ADR-141 D-L / D-N
    'clinical.allergies.record', // RecordAllergy + UpdateAllergy + VerifyAllergy + ResolveAllergy (C.5) — one key covers all four; ADR-141 D-L
    'clinical.care_team.read',   // GetEncounterCareTeam + GetCareTeamMembership (C.6) — protected ORIGINATING-facility-scoped reads; ADR-133 / ADR-142 D-N
    'clinical.care_team.record', // AddCareTeamMember + EndCareTeamMembership + ChangeProviderOfRecord (C.6) — one key covers all three; ADR-142 D-N
    'clinical.plans.read',       // GetClinicalPlan + GetPatientClinicalPlans (C.7) — protected ORIGINATING-facility-scoped reads; ADR-143 D-Q
    'clinical.plans.record',     // CreateClinicalPlan + UpdateClinicalPlan + CloseClinicalPlan (C.7) — one key covers all three; ADR-143 D-Q
    'clinical.prescribing.read', // GetPrescribingDecision + GetPatientPrescribingDecisions (C.7) — protected ORIGINATING-facility-scoped reads; ADR-143 D-Q
    'clinical.prescribing.record', // RecordPrescribingDecision + CorrectPrescribingDecision + DiscontinuePrescribingDecision (C.7); ADR-143 D-Q
    'clinical.triage.read',      // GetTriageAssessment + GetEncounterTriageAssessments (C.9) — protected ORIGINATING-facility-scoped reads; ADR-133 / ADR-145 D-L
    'clinical.triage.record',    // RecordTriageAssessment + CorrectTriageAssessment (C.9) — one key covers both (a correction / re-triage is a new record); ADR-145 D-L
    'clinical.consultations.read', // Open the clinician consultation workspace.
    'clinical.consultations.create', // Create clinician-authored consultation notes.
    'clinical.procedures.read',     // GetProcedure + GetProcedureDefinition(s) + GetEncounterProcedures + GetPatientProcedures (CP.1) — protected reads; ADR-146 D-O
    'clinical.procedures.request',  // RequestProcedure + AuthorizeProcedure + MarkProcedureReady + CancelProcedure + DeclineProcedure (CP.1); ADR-146 D-O
    'clinical.procedures.protocol_request', // Directly request only explicitly protocol-approved definitions from Reception.
    'clinical.procedures.protocol_manage', // Approve or revoke facility-scoped direct-service protocol references.
    'clinical.procedures.perform',  // StartProcedure + RecordProcedureOutcome + RecordProcedureMaterials + AddProcedureNote + AbortProcedure (CP.1); ADR-146 D-O
    'clinical.procedures.complete', // CompleteProcedure ALONE — the Billing-triggering terminal fact (CP.1); ADR-146 D-O
    'clinical.procedures.amend',    // AmendProcedure ALONE — the privileged post-completion correction (CP.1); ADR-146 D-O
];
