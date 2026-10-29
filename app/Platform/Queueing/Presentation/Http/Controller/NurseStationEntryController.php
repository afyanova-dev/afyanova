<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Presentation\Http\Controller;

use App\Domains\Clinical\Application\Query\GetEncounterTriageAssessments;
use App\Domains\Clinical\Application\Query\GetPatientVitalSigns;
use App\Domains\Clinical\Contracts\Dto\TriageAssessmentSummary;
use App\Domains\Clinical\Contracts\Dto\VitalSignsObservationSummary;
use App\Domains\Encounter\Contracts\EncounterApi;
use App\Domains\Patient\Application\Query\GetPatientProfile;
use App\Domains\Patient\Contracts\Dto\PatientProfileData;
use App\Domains\Patient\Contracts\PatientApi;
use App\Models\User;
use App\Platform\Queueing\Domain\Enum\QueueStatus;
use App\Platform\Queueing\Domain\Enum\ServiceCapability;
use App\Platform\Queueing\Domain\Model\Queue;
use App\Platform\Queueing\Domain\Model\QueueEntry;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class NurseStationEntryController
{
    public function __invoke(
        Request $request,
        TenantContext $context,
        EncounterApi $encounterApi,
        PatientApi $patientApi,
        QueryBus $queryBus,
        string $entry,
    ): Response {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');
        abort_unless($user->can('queue.ticket.view'), 403, 'You do not have access to service queues.');
        abort_unless($user->can('clinical.access'), 403, 'You do not have access to clinical workspaces.');
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
            $ticket->queue?->service_capability === ServiceCapability::TRIAGE->value,
            404,
        );

        $activeTriageEntries = QueueEntry::query()
            ->where('facility_id', $facilityId)
            ->whereIn('status', [
                QueueStatus::WAITING->value,
                QueueStatus::CALLED->value,
                QueueStatus::IN_SERVICE->value,
            ])
            ->whereHas('queue', static fn ($query) => $query
                ->where('service_capability', ServiceCapability::TRIAGE->value));
        $previousEntry = (clone $activeTriageEntries)
            ->where(static fn ($query) => $query
                ->where('created_at', '<', $ticket->created_at)
                ->orWhere(static fn ($tie) => $tie
                    ->where('created_at', $ticket->created_at)
                    ->where('id', '<', $ticket->id)))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first(['id', 'ticket_number']);
        $nextEntry = (clone $activeTriageEntries)
            ->where(static fn ($query) => $query
                ->where('created_at', '>', $ticket->created_at)
                ->orWhere(static fn ($tie) => $tie
                    ->where('created_at', $ticket->created_at)
                    ->where('id', '>', $ticket->id)))
            ->orderBy('created_at')
            ->orderBy('id')
            ->first(['id', 'ticket_number']);
        $queuePosition = (clone $activeTriageEntries)
            ->where(static fn ($query) => $query
                ->where('created_at', '<', $ticket->created_at)
                ->orWhere(static fn ($tie) => $tie
                    ->where('created_at', $ticket->created_at)
                    ->where('id', '<=', $ticket->id)))
            ->count();
        $queueTotal = (clone $activeTriageEntries)->count();

        $encounter = $encounterApi->getEncounterContext($ticket->encounter_id);
        abort_unless(
            $encounter->encounterId === $ticket->encounter_id
                && $encounter->patientId === $ticket->patient_id
                && $encounter->facilityId === $facilityId,
            404,
        );
        $patient = $patientApi->getPatientSummary($ticket->patient_id);
        /** @var PatientProfileData $patientProfile */
        $patientProfile = $queryBus->dispatch(new GetPatientProfile($ticket->patient_id, $facilityId));

        /** @var list<VitalSignsObservationSummary> $observations */
        $observations = $queryBus->dispatch(new GetPatientVitalSigns($ticket->patient_id));
        $vitals = array_values(array_filter(
            $observations,
            static fn (VitalSignsObservationSummary $observation): bool => $observation->encounterId === $ticket->encounter_id
                && $observation->supersededAt === null,
        ));
        $vitals = array_slice($vitals, 0, 5);

        /** @var list<TriageAssessmentSummary> $assessments */
        $assessments = $queryBus->dispatch(new GetEncounterTriageAssessments($ticket->encounter_id, currentOnly: true));
        $currentTriage = $assessments[0] ?? null;

        return Inertia::render('NurseStation/Show', [
            'entry' => [
                'id' => $ticket->id,
                'ticketNumber' => $ticket->ticket_number,
                'queue' => $ticket->queue?->name ?? 'Triage',
                'status' => $ticket->status->value,
                'priority' => $ticket->priority->value,
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
            'encounter' => [
                'encounterId' => $encounter->encounterId,
                'status' => $encounter->status,
                'type' => $encounter->encounterType,
            ],
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
            ], $vitals),
            'triage' => $currentTriage instanceof TriageAssessmentSummary ? [
                'chiefComplaint' => $currentTriage->chiefComplaint,
                'priority' => $currentTriage->triagePriority,
                'acuity' => $currentTriage->acuity,
                'consciousness' => $currentTriage->consciousness,
                'occurredAt' => $currentTriage->occurredAt,
            ] : null,
            'canStartService' => $user->can('queue.ticket.service')
                && $ticket->status === QueueStatus::CALLED
                && is_string($ticket->called_room_id)
                && DB::table('qms_queue_room_staff')
                    ->where('tenant_id', $ticket->tenant_id)
                    ->where('facility_id', $ticket->facility_id)
                    ->where('queue_id', $ticket->queue_id)
                    ->where('room_id', $ticket->called_room_id)
                    ->where('user_id', $user->id)
                    ->exists(),
            'canRecordVitals' => $user->can('clinical.vitals.record'),
            'canRecordTriage' => $user->can('clinical.triage.record'),
            'consultationQueues' => Queue::query()
                ->where('facility_id', $facilityId)
                ->where('service_capability', ServiceCapability::CONSULTATION->value)
                ->where('is_active', true)
                ->where('operating_status', '!=', 'CLOSED')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(static fn (Queue $queue): array => [
                    'id' => $queue->id,
                    'name' => $queue->name,
                ])
                ->all(),
            'canHandoff' => $user->can('queue.ticket.transfer')
                && $ticket->status === QueueStatus::IN_SERVICE,
        ]);
    }
}
