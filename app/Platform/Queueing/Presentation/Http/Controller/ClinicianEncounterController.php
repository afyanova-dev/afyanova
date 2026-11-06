<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Presentation\Http\Controller;

use App\Domains\Clinical\Contracts\ClinicalApi;
use App\Domains\Clinical\Contracts\Dto\AllergySummary;
use App\Domains\Clinical\Contracts\Dto\ClinicalNoteSummary;
use App\Domains\Clinical\Contracts\Dto\ClinicalPlanSummary;
use App\Domains\Clinical\Contracts\Dto\DiagnosisSummary;
use App\Domains\Clinical\Contracts\Dto\PrescribingDecisionSummary;
use App\Domains\Clinical\Contracts\Dto\ProblemSummary;
use App\Domains\Clinical\Contracts\Dto\ProcedureDefinitionSummary;
use App\Domains\Clinical\Contracts\Dto\ProcedureSummary;
use App\Domains\Clinical\Contracts\Dto\TriageAssessmentSummary;
use App\Domains\Clinical\Contracts\Dto\VitalSignsObservationSummary;
use App\Domains\Encounter\Contracts\EncounterApi;
use App\Domains\Inventory\Contracts\InventoryApi;
use App\Domains\Laboratory\Contracts\Dto\LabPanelSummary;
use App\Domains\Laboratory\Contracts\Dto\LabTestDefinitionSummary;
use App\Domains\Laboratory\Contracts\LaboratoryApi;
use App\Domains\Organization\Contracts\OrganizationApi;
use App\Domains\Patient\Contracts\Dto\PatientProfileData;
use App\Domains\Patient\Contracts\PatientApi;
use App\Models\User;
use App\Platform\Queueing\Contracts\QueueingApi;
use App\Platform\Queueing\Domain\Enum\QueueStatus;
use App\Platform\Queueing\Domain\Enum\ServiceCapability;
use App\Platform\Queueing\Domain\Model\QueueEntry;
use App\Shared\Capability\FacilityCapabilities;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ClinicianEncounterController
{
    use PresentsQueueEntries;

    public function __invoke(
        Request $request,
        TenantContext $context,
        EncounterApi $encounterApi,
        ClinicalApi $clinicalApi,
        PatientApi $patientApi,
        LaboratoryApi $laboratoryApi,
        OrganizationApi $organizationApi,
        QueueingApi $queueingApi,
        InventoryApi $inventoryApi,
        FacilityCapabilities $facilityCapabilities,
        string $entry,
    ): Response {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');
        abort_unless($user->can('queue.ticket.view'), 403, 'You do not have access to service queues.');
        abort_unless($user->can('clinical.access'), 403, 'You do not have access to clinical workspaces.');
        abort_unless($user->can('clinical.consultations.read'), 403, 'You do not have access to clinician consultations.');
        abort_unless($user->can('patient.patients.read'), 403, 'You do not have access to patient records.');
        abort_unless($user->can('encounter.encounters.read'), 403, 'You do not have access to encounter records.');
        abort_unless($user->can('clinical.vitals.read'), 403, 'You do not have access to vital signs.');
        abort_unless($user->can('clinical.triage.read'), 403, 'You do not have access to triage assessments.');

        $ticket = QueueEntry::query()
            ->with('queue:id,name,code,service_capability')
            ->where('facility_id', $facilityId)
            ->whereIn('status', [
                QueueStatus::WAITING->value,
                QueueStatus::CALLED->value,
                QueueStatus::IN_SERVICE->value,
            ])
            ->findOrFail($entry);
        abort_unless(
            $ticket->queue?->service_capability === ServiceCapability::CONSULTATION->value,
            404,
        );

        $activeConsultationEntries = QueueEntry::query()
            ->where('facility_id', $facilityId)
            ->whereIn('status', [
                QueueStatus::WAITING->value,
                QueueStatus::CALLED->value,
                QueueStatus::IN_SERVICE->value,
            ])
            ->whereHas('queue', static fn ($query) => $query
                ->where('service_capability', ServiceCapability::CONSULTATION->value));
        $previousEntry = (clone $activeConsultationEntries)
            ->where(static fn ($query) => $query
                ->where('created_at', '<', $ticket->created_at)
                ->orWhere(static fn ($tie) => $tie
                    ->where('created_at', $ticket->created_at)
                    ->where('id', '<', $ticket->id)))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first(['id', 'ticket_number']);
        $nextEntry = (clone $activeConsultationEntries)
            ->where(static fn ($query) => $query
                ->where('created_at', '>', $ticket->created_at)
                ->orWhere(static fn ($tie) => $tie
                    ->where('created_at', $ticket->created_at)
                    ->where('id', '>', $ticket->id)))
            ->orderBy('created_at')
            ->orderBy('id')
            ->first(['id', 'ticket_number']);
        $queuePosition = (clone $activeConsultationEntries)
            ->where(static fn ($query) => $query
                ->where('created_at', '<', $ticket->created_at)
                ->orWhere(static fn ($tie) => $tie
                    ->where('created_at', $ticket->created_at)
                    ->where('id', '<=', $ticket->id)))
            ->count();
        $queueTotal = (clone $activeConsultationEntries)->count();

        $encounter = $encounterApi->getEncounterContext($ticket->encounter_id);
        abort_unless(
            $encounter->encounterId === $ticket->encounter_id
                && $encounter->patientId === $ticket->patient_id
                && $encounter->facilityId === $facilityId,
            404,
        );
        $patient = $patientApi->getPatientSummary($ticket->patient_id);
        /** @var PatientProfileData $patientProfile */
        $patientProfile = $patientApi->getPatientProfile($ticket->patient_id, $facilityId);

        /** @var list<VitalSignsObservationSummary> $observations */
        $observations = $clinicalApi->getPatientVitalSigns($ticket->patient_id);
        $vitals = array_values(array_filter(
            $observations,
            static fn (VitalSignsObservationSummary $observation): bool => $observation->encounterId === $ticket->encounter_id
                && $observation->supersededAt === null,
        ));

        /** @var list<TriageAssessmentSummary> $assessments */
        $assessments = $clinicalApi->getEncounterTriageAssessments($ticket->encounter_id, currentOnly: true);
        $currentTriage = $assessments[0] ?? null;
        $canReadProcedures = $user->can('clinical.procedures.read');
        /** @var list<ProcedureDefinitionSummary> $procedureDefinitions */
        $procedureDefinitions = $canReadProcedures
            ? $clinicalApi->getProcedureDefinitions(activeOnly: true)
            : [];
        /** @var list<ProcedureSummary> $procedures */
        $procedures = $canReadProcedures
            ? $clinicalApi->getEncounterProcedures($ticket->encounter_id)
            : [];
        $canReadPrescribing = $user->can('clinical.prescribing.read');
        /** @var list<PrescribingDecisionSummary> $prescribingDecisions */
        $prescribingDecisions = $canReadPrescribing
            ? $clinicalApi->getPatientPrescribingDecisions($ticket->patient_id)
            : [];
        $prescribingDecisions = array_values(array_filter(
            $prescribingDecisions,
            static fn (PrescribingDecisionSummary $decision): bool => $decision->encounterId === $ticket->encounter_id,
        ));
        $medicationCatalog = ($canReadPrescribing || $user->can('clinical.prescribing.record'))
            ? $inventoryApi->getItems('MEDICATION', true)
            : [];
        $canRecordLabOrders = $user->can('laboratory.orders.create');
        /** @var list<LabTestDefinitionSummary> $labTestDefinitions */
        $labTestDefinitions = $canRecordLabOrders
            ? $laboratoryApi->getLabTestDefinitions(active: true)
            : [];
        $labTestDefinitions = array_values(array_filter(
            $labTestDefinitions,
            static fn (LabTestDefinitionSummary $definition): bool => $definition->active
                && $definition->availabilityStatus === LabTestDefinitionSummary::AVAILABILITY_AVAILABLE
                && ($definition->requiredCapabilityCode === null
                    || $facilityCapabilities->has($facilityId, $definition->requiredCapabilityCode)),
        ));
        $orderableLabDefinitionIds = array_fill_keys(
            array_map(static fn (LabTestDefinitionSummary $definition): string => $definition->definitionId, $labTestDefinitions),
            true,
        );
        /** @var list<LabPanelSummary> $labPanels */
        $labPanels = $canRecordLabOrders
            ? $laboratoryApi->getLabPanels(active: true)
            : [];
        $labPanels = array_values(array_filter(
            $labPanels,
            static function (LabPanelSummary $panel) use ($orderableLabDefinitionIds): bool {
                if (! $panel->active || $panel->members === []) {
                    return false;
                }

                foreach ($panel->members as $member) {
                    if (! isset($orderableLabDefinitionIds[$member['testDefinitionId']])) {
                        return false;
                    }
                }

                return true;
            },
        ));
        $diagnosisCatalog = ($user->can('clinical.diagnoses.read') || $user->can('clinical.diagnoses.record'))
            ? $clinicalApi->getActiveDiagnosisCatalog()
            : [];

        $canRecordNote = $user->can('clinical.notes.create')
            && $user->can('clinical.consultations.create');
        $canRecordDiagnosis = $user->can('clinical.diagnoses.record');
        $canRecordPlan = $user->can('clinical.plans.record');

        $canReadNotes = $user->can('clinical.notes.read');
        /** @var list<ClinicalNoteSummary> $notes */
        $notes = $canReadNotes
            ? $clinicalApi->getEncounterClinicalNotes($ticket->encounter_id)
            : [];

        $canReadDiagnoses = $user->can('clinical.diagnoses.read');
        /** @var list<DiagnosisSummary> $diagnoses */
        $diagnoses = $canReadDiagnoses
            ? $clinicalApi->getEncounterDiagnoses($ticket->encounter_id)
            : [];

        $canReadAllergies = $user->can('clinical.allergies.read');
        /** @var list<AllergySummary> $allergies */
        $allergies = $canReadAllergies
            ? $clinicalApi->getPatientAllergies($ticket->patient_id, 'ACTIVE')
            : [];

        $canReadProblems = $user->can('clinical.problems.read');
        /** @var list<ProblemSummary> $problems */
        $problems = $canReadProblems
            ? $clinicalApi->getPatientProblems($ticket->patient_id, 'ACTIVE')
            : [];

        $canReadPlans = $user->can('clinical.plans.read');
        /** @var list<ClinicalPlanSummary> $plans */
        $plans = $canReadPlans
            ? $clinicalApi->getPatientClinicalPlans($ticket->patient_id)
            : [];
        $plans = array_values(array_filter(
            $plans,
            static fn (ClinicalPlanSummary $plan): bool => $plan->encounterId === $ticket->encounter_id,
        ));

        $canReadMedications = $canReadPrescribing;
        /** @var list<PrescribingDecisionSummary> $medications */
        $medications = array_values(array_filter(
            $prescribingDecisions,
            static fn (PrescribingDecisionSummary $decision): bool => $decision->status === 'ACTIVE',
        ));

        $userId = (string) $user->getAuthIdentifier();
        $tenantId = (string) $context->tenantId();
        $documentationDrafts = [
            'note' => $canRecordNote
                ? $clinicalApi->getDocumentationDraft($tenantId, $facilityId, $userId, $ticket->encounter_id, 'NOTE')
                : null,
            'diagnosis' => $canRecordDiagnosis
                ? $clinicalApi->getDocumentationDraft($tenantId, $facilityId, $userId, $ticket->encounter_id, 'DIAGNOSIS')
                : null,
            'plan' => $canRecordPlan
                ? $clinicalApi->getDocumentationDraft($tenantId, $facilityId, $userId, $ticket->encounter_id, 'PLAN')
                : null,
        ];

        $callRooms = [];
        $canCall = false;
        if ($ticket->status === QueueStatus::WAITING && $user->can('queue.ticket.call')) {
            $callRooms = $this->callRoomsFor($ticket, $user, $organizationApi);
            $canCall = ! $this->hasRoomStaffRestrictions($ticket) || $callRooms !== [];
        }

        return Inertia::render('Clinician/Show', [
            'entry' => [
                'id' => $ticket->id,
                'ticketNumber' => $ticket->ticket_number,
                'queue' => $ticket->queue->name,
                'status' => $ticket->status->value,
                'priority' => $ticket->priority->value,
            ],
            'callRooms' => $callRooms,
            'canCall' => $canCall,
            'canStartService' => $user->can('queue.ticket.service')
                && $ticket->status === QueueStatus::CALLED
                && ($ticket->called_room_id === null || $this->userCanAccessLocation(
                    $ticket,
                    (string) $user->getAuthIdentifier(),
                )),
            'patient' => [
                'patientId' => $patient->patientId,
                'patientNumber' => $patient->patientNumber,
                'displayName' => $patient->displayName,
                'dateOfBirth' => $patient->dateOfBirth,
                'sex' => $patient->sex,
                'status' => $patient->status,
                'isProvisional' => $patient->isProvisional,
                'photoUrl' => $patientProfile->photoDocumentId !== null
                    ? route('patient.photo', ['patientId' => $patient->patientId])
                    : null,
            ],
            'queueNavigation' => [
                'position' => $queuePosition,
                'total' => $queueTotal,
                'previous' => $previousEntry === null ? null : [
                    'id' => $previousEntry->id,
                    'ticketNumber' => $previousEntry->ticket_number,
                ],
                'next' => $nextEntry === null ? null : [
                    'id' => $nextEntry->id,
                    'ticketNumber' => $nextEntry->ticket_number,
                ],
            ],
            'encounter' => [
                'encounterId' => $encounter->encounterId,
                'status' => $encounter->status,
                'type' => $encounter->encounterType,
            ],
            'triage' => $currentTriage instanceof TriageAssessmentSummary ? [
                'chiefComplaint' => $currentTriage->chiefComplaint,
                'priority' => $currentTriage->triagePriority,
                'acuity' => $currentTriage->acuity,
                'consciousness' => $currentTriage->consciousness,
                'occurredAt' => $currentTriage->occurredAt,
            ] : null,
            'vitals' => array_map(static fn (VitalSignsObservationSummary $observation): array => [
                'temperature' => $observation->temperature,
                'heartRate' => $observation->heartRate,
                'respiratoryRate' => $observation->respiratoryRate,
                'systolicBp' => $observation->systolicBp,
                'diastolicBp' => $observation->diastolicBp,
                'oxygenSaturation' => $observation->oxygenSaturation,
                'weight' => $observation->weight,
                'height' => $observation->height,
                'painScore' => $observation->painScore,
                'occurredAt' => $observation->occurredAt,
                'authoredContext' => $observation->authoredContext,
            ], array_slice($vitals, 0, 5)),
            'procedureDefinitions' => array_map(static fn ($definition): array => [
                'definitionId' => $definition->definitionId,
                'code' => $definition->code,
                'name' => $definition->name,
                'queueConfigured' => $queueingApi->procedureQueueConfigured($facilityId, $definition->definitionId),
            ], $procedureDefinitions),
            'labTestCatalog' => array_map(static fn (LabTestDefinitionSummary $definition): array => [
                'definitionId' => $definition->definitionId,
                'code' => $definition->code,
                'name' => $definition->name,
                'category' => $definition->category,
                'specimenType' => $definition->specimenType,
            ], $labTestDefinitions),
            'labPanelCatalog' => array_map(static fn (LabPanelSummary $panel): array => [
                'panelId' => $panel->panelId,
                'code' => $panel->code,
                'name' => $panel->name,
                'category' => $panel->category,
            ], $labPanels),
            'procedures' => array_map(static fn ($procedure): array => [
                'procedureId' => $procedure->procedureId,
                'procedureNumber' => $procedure->procedureNumber,
                'definitionId' => $procedure->definitionId,
                'status' => $procedure->status,
                'authorizationBasis' => $procedure->authorizationBasis,
                'authorizationProtocolReference' => $procedure->authorizationProtocolReference,
                'indication' => $procedure->indication,
            ], $procedures),
            'canRequestProcedure' => $canReadProcedures && $user->can('clinical.procedures.request'),
            'canRecordNote' => $canRecordNote,
            'canRecordDiagnosis' => $canRecordDiagnosis,
            'canRecordPlan' => $canRecordPlan,
            'canReadNotes' => $canReadNotes,
            'canSignNotes' => $user->can('clinical.notes.sign'),
            'canAmendNotes' => $user->can('clinical.notes.amend'),
            'canReadDiagnoses' => $canReadDiagnoses,
            'canReadPlans' => $canReadPlans,
            'canReadAllergies' => $canReadAllergies,
            'canReadProblems' => $canReadProblems,
            'canReadMedications' => $canReadMedications,
            'prescribingDecisions' => array_map(static fn (PrescribingDecisionSummary $decision): array => [
                'prescribingDecisionId' => $decision->prescribingDecisionId,
                'medicationName' => $decision->medicationName,
                'dose' => $decision->dose,
                'route' => $decision->route,
                'frequency' => $decision->frequency,
                'duration' => $decision->duration,
                'quantity' => $decision->quantity,
                'quantityValue' => $decision->quantityValue,
                'quantityUnit' => $decision->quantityUnit,
                'dosingInstruction' => $decision->dosingInstruction,
                'status' => $decision->status,
                'occurredAt' => $decision->occurredAt,
            ], $prescribingDecisions),
            'medicationCatalog' => array_map(static fn ($item): array => [
                'id' => $item->itemId,
                'code' => $item->code,
                'name' => $item->name,
                'unitOfMeasure' => $item->uom,
                'category' => $item->category,
            ], $medicationCatalog),
            'diagnosisCatalog' => $diagnosisCatalog,
            'notes' => array_map(static fn (ClinicalNoteSummary $note): array => [
                'clinicalNoteId' => $note->clinicalNoteId,
                'authoredContext' => $note->authoredContext,
                'noteType' => $note->noteType,
                'title' => $note->title,
                'body' => $note->body,
                'status' => $note->status,
                'occurredAt' => $note->occurredAt,
                'recordedAt' => $note->recordedAt,
                'signedAt' => $note->signedAt,
                'amendmentCount' => $note->amendmentCount,
            ], $notes),
            'diagnoses' => array_map(static fn (DiagnosisSummary $diagnosis): array => [
                'diagnosisId' => $diagnosis->diagnosisId,
                'description' => $diagnosis->description,
                'code' => $diagnosis->code,
                'codeSystem' => $diagnosis->codeSystem,
                'clinicalStatus' => $diagnosis->clinicalStatus,
                'occurredAt' => $diagnosis->occurredAt,
                'recordedAt' => $diagnosis->recordedAt,
            ], $diagnoses),
            'plans' => array_map(static fn (ClinicalPlanSummary $plan): array => [
                'planId' => $plan->planId,
                'planType' => $plan->planType,
                'clinicalIntent' => $plan->clinicalIntent,
                'status' => $plan->status,
                'followUp' => $plan->followUp,
                'occurredAt' => $plan->occurredAt,
            ], $plans),
            'allergies' => array_map(static fn (AllergySummary $allergy): array => [
                'allergyId' => $allergy->allergyId,
                'substanceName' => $allergy->substanceName,
                'reactionDescription' => $allergy->reactionDescription,
                'severity' => $allergy->severity,
                'verificationStatus' => $allergy->verificationStatus,
                'clinicalStatus' => $allergy->clinicalStatus,
            ], $allergies),
            'problems' => array_map(static fn (ProblemSummary $problem): array => [
                'problemId' => $problem->problemId,
                'description' => $problem->description,
                'code' => $problem->code,
                'codeSystem' => $problem->codeSystem,
                'status' => $problem->status,
            ], $problems),
            'medications' => array_map(static fn (PrescribingDecisionSummary $medication): array => [
                'medicationId' => $medication->prescribingDecisionId,
                'medicationName' => $medication->medicationName,
                'dose' => $medication->dose,
                'route' => $medication->route,
                'frequency' => $medication->frequency,
                'status' => $medication->status,
                'occurredAt' => $medication->occurredAt,
            ], $medications),
            'drafts' => $documentationDrafts,
            'canReadPrescribing' => $canReadPrescribing,
            'canRecordPrescribing' => $user->can('clinical.prescribing.record'),
            'canRecordLabOrders' => $canRecordLabOrders,
            'canStartEncounter' => $user->can('encounter.encounters.update'),
            'canChangeDisposition' => $user->can('encounter.encounters.manage_disposition'),
            'canCompleteEncounter' => $user->can('encounter.encounters.complete'),
        ]);
    }
}
