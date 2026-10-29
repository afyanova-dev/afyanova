<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Presentation\Http\Controller;

use App\Domains\Clinical\Contracts\ClinicalApi;
use App\Domains\Clinical\Contracts\Dto\ProcedureDefinitionSummary;
use App\Domains\Clinical\Contracts\Dto\ProcedureProtocolApprovalSummary;
use App\Domains\Encounter\Application\Command\CreateEncounter;
use App\Domains\Encounter\Application\Command\CreateEncounterResult;
use App\Domains\Encounter\Domain\Enum\EncounterType;
use App\Domains\Encounter\Domain\Exception\ActiveEncounterExists;
use App\Domains\Encounter\Domain\Exception\EncounterTypeNotAvailable;
use App\Domains\Encounter\Domain\Exception\PatientNotEligibleForEncounter;
use App\Domains\Encounter\Presentation\Http\Request\CheckInEncounterRequest;
use App\Domains\Patient\Contracts\PatientApi;
use App\Domains\Scheduling\Contracts\SchedulingApi;
use App\Models\User;
use App\Platform\Queueing\Contracts\DTO\PlaceInQueueDto;
use App\Platform\Queueing\Contracts\QueueingApi;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class ReceptionCheckInController
{
    public function __invoke(
        CheckInEncounterRequest $request,
        TenantContext $context,
        CommandBus $commandBus,
        QueueingApi $queueingApi,
        SchedulingApi $schedulingApi,
        PatientApi $patientApi,
        ClinicalApi $clinicalApi,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');

        // Check-in alone needs encounter creation; issuing a queue ticket on
        // the way through additionally needs the queue placement permission.
        if ($request->filled('queue_id')) {
            abort_unless($user->can('queue.ticket.place'), 403, 'You do not have permission to place patients in queues.');
        }

        $encounterId = (string) Str::uuid7();
        $patientId = (string) $request->input('patient_id');
        $encounterType = EncounterType::from((string) $request->input('encounter_type'));
        $departmentId = $request->filled('department_id') ? (string) $request->input('department_id') : null;
        $unitId = $request->filled('unit_id') ? (string) $request->input('unit_id') : null;
        $queueId = $request->filled('queue_id') ? (string) $request->input('queue_id') : null;
        $priority = (string) $request->input('priority', 'ROUTINE');
        $appointmentId = $request->filled('appointment_id') ? (string) $request->input('appointment_id') : null;
        $protocolDefinitionId = $request->filled('protocol_procedure_definition_id')
            ? (string) $request->input('protocol_procedure_definition_id')
            : null;
        $protocolDefinition = null;

        if ($protocolDefinitionId !== null) {
            abort_unless(
                $user->can('clinical.procedures.protocol_request')
                    && $user->can('clinical.procedures.read')
                    && $user->can('queue.ticket.place'),
                403,
                'You do not have permission to request protocol-approved procedures.',
            );
            try {
                /** @var list<ProcedureDefinitionSummary> $definitions */
                $definitions = $clinicalApi->getProcedureDefinitions();
                $protocolDefinition = collect($definitions)->first(
                    static fn (ProcedureDefinitionSummary $definition): bool => $definition->definitionId === $protocolDefinitionId,
                );
                /** @var list<ProcedureProtocolApprovalSummary> $approvals */
                $approvals = $clinicalApi->getDirectProcedureProtocolApprovals($facilityId);
                $protocolApproval = collect($approvals)->first(
                    static fn (ProcedureProtocolApprovalSummary $approval): bool => $approval->definitionId === $protocolDefinitionId,
                );
            } catch (\Throwable $e) {
                Log::warning('Protocol procedure definitions could not be verified at check-in', [
                    'facility_id' => $facilityId,
                    'definition_id' => $protocolDefinitionId,
                    'error' => $e->getMessage(),
                ]);

                return $this->queueRoutingError($request, 'The approved procedure could not be verified. Please try again.');
            }

            if (! $protocolDefinition instanceof ProcedureDefinitionSummary
                || ! $protocolApproval instanceof ProcedureProtocolApprovalSummary
                || $protocolApproval->definitionVersion !== $protocolDefinition->version) {
                return $this->queueRoutingError($request, 'This procedure does not have a current direct-service protocol approval.');
            }
            $mappedQueueId = $queueingApi->procedureQueueId($facilityId, $protocolDefinitionId);
            if ($mappedQueueId === null || $mappedQueueId !== $queueId) {
                return $this->queueRoutingError($request, 'Select the configured procedure queue for this approved service.');
            }
        }

        if ($queueId !== null) {
            try {
                $selectedQueue = collect($queueingApi->listFacilityQueues($facilityId, true))
                    ->first(static fn ($queue): bool => $queue->id === $queueId);
            } catch (\Throwable $e) {
                Log::warning('Queue routing could not be verified during reception check-in', [
                    'facility_id' => $facilityId,
                    'queue_id' => $queueId,
                    'error' => $e->getMessage(),
                ]);

                return $this->queueRoutingError(
                    $request,
                    'Queue routing could not be verified. Please try again or check in without queue placement.',
                );
            }

            if ($selectedQueue === null) {
                return $this->queueRoutingError(
                    $request,
                    'The selected queue is no longer available. Choose an active queue or check in without queue placement.',
                );
            }

            if ($selectedQueue->roomNames === []) {
                return $this->queueRoutingError(
                    $request,
                    'This queue has no active rooms assigned. Ask a queue administrator to configure its service rooms.',
                );
            }

            if ($queueingApi->serviceCapabilityRequiresOrderProvenance($selectedQueue->serviceCapability)
                && ($protocolDefinition === null || $protocolDefinitionId === null || $queueId !== $queueingApi->procedureQueueId($facilityId, $protocolDefinitionId))) {
                return $this->queueRoutingError(
                    $request,
                    'This service queue requires an authorized order and cannot be selected during check-in yet. Check in without queue placement and arrange the service after its order is recorded.',
                );
            }
        }

        if ($appointmentId !== null) {
            $arrivalError = $this->recordAppointmentArrival(
                $schedulingApi,
                $appointmentId,
                $patientId,
                $facilityId,
            );
            if ($arrivalError !== null) {
                return back()->withErrors([
                    'check_in' => $arrivalError,
                ]);
            }
        }

        $command = new CreateEncounter(
            encounterId: $encounterId,
            patientId: $patientId,
            actorId: (string) $user->getAuthIdentifier(),
            encounterType: $protocolDefinition !== null ? EncounterType::Procedure : $encounterType,
            planned: false,
            departmentId: $departmentId,
            unitId: $unitId,
            source: 'RECEPTION',
        );

        try {
            /** @var CreateEncounterResult $result */
            $result = $commandBus->dispatch($command);
        } catch (ActiveEncounterExists) {
            /** @var string|null $patientName */
            $patientName = null;
            /** @var string|null $patientNumber */
            $patientNumber = null;
            try {
                $summary = $patientApi->getPatientSummary($patientId);
                $patientName = $summary->displayName;
                $patientNumber = $summary->patientNumber;
            } catch (\Throwable) {
                // Fallback gracefully if summary cannot be resolved
            }

            $message = $patientName !== null && $patientNumber !== null
                ? "{$patientName} ({$patientNumber}) already has an active visit checked in at this facility today."
                : 'This patient already has an active visit checked in at this facility today.';

            return back()->withErrors([
                'check_in' => $message,
            ]);
        } catch (PatientNotEligibleForEncounter|EncounterTypeNotAvailable $e) {
            return back()->withErrors([
                'check_in' => $e->getMessage(),
            ]);
        }

        $ticketNumber = null;
        if ($protocolDefinition !== null && $protocolDefinitionId !== null) {
            try {
                $clinicalApi->requestProtocolProcedure(
                    procedureId: (string) Str::uuid7(),
                    actorId: (string) $user->getAuthIdentifier(),
                    patientId: $patientId,
                    encounterId: $result->encounterId,
                    definitionId: $protocolDefinitionId,
                    indication: $request->filled('protocol_procedure_indication')
                        ? trim((string) $request->input('protocol_procedure_indication'))
                        : null,
                    protocolReference: $protocolApproval->protocolReference,
                );
            } catch (\Throwable $e) {
                Log::warning('Protocol procedure request failed after reception check-in', [
                    'encounter_id' => $result->encounterId,
                    'definition_id' => $protocolDefinitionId,
                    'error' => $e->getMessage(),
                ]);

                return $this->queueRoutingError(
                    $request,
                    "Visit {$result->encounterNumber} was created, but no protocol procedure order was issued. Do not check the patient in again; arrange the procedure order for this visit.",
                );
            }
        } elseif ($queueId !== null && $queueId !== '') {
            try {
                $queueEntry = $queueingApi->place(new PlaceInQueueDto(
                    encounterId: $result->encounterId,
                    patientId: $patientId,
                    queueId: $queueId,
                    priority: $priority,
                ));
                $ticketNumber = $queueEntry->ticketNumber;
            } catch (\Throwable $e) {
                Log::warning('Platform queue placement deferred during reception check-in', [
                    'encounter_id' => $result->encounterId,
                    'queue_id' => $queueId,
                    'error' => $e->getMessage(),
                ]);

                return $this->queueRoutingError(
                    $request,
                    "Visit {$result->encounterNumber} was created, but no queue ticket was issued. Do not check the patient in again; arrange queue placement for this existing visit before service.",
                );
            }
        }

        $msg = "Patient checked in successfully ({$result->encounterNumber}).";
        if ($protocolDefinition !== null) {
            $msg .= ' Protocol-approved procedure requested and authorized; it will route to its configured service queue.';
        }
        if ($ticketNumber !== null) {
            $msg .= " Queue ticket #{$ticketNumber} issued.";
        }

        if ($request->filled('return_to')) {
            $returnTo = (string) $request->input('return_to');
            if (str_starts_with($returnTo, '/') && ! str_starts_with($returnTo, '//')) {
                return redirect($this->clearReceptionPatientSelection($returnTo))->with('status', $msg);
            }
        }

        return back()->with('status', $msg);
    }

    private function clearReceptionPatientSelection(string $returnTo): string
    {
        $parts = parse_url($returnTo);
        if (! is_array($parts) || ($parts['path'] ?? null) !== '/workspaces/reception' || ! isset($parts['query'])) {
            return $returnTo;
        }

        parse_str($parts['query'], $query);
        unset($query['patient_id']);

        $url = $parts['path'];
        if ($query !== []) {
            $url .= '?'.http_build_query($query);
        }
        if (isset($parts['fragment'])) {
            $url .= '#'.$parts['fragment'];
        }

        return $url;
    }

    private function recordAppointmentArrival(
        SchedulingApi $schedulingApi,
        string $appointmentId,
        string $patientId,
        string $facilityId,
    ): ?string {
        $summary = $schedulingApi->getAppointmentSummary($appointmentId);
        if ($summary === null) {
            return 'Appointment was not found.';
        }
        if ($summary->patientId !== $patientId) {
            return 'Appointment does not match this patient.';
        }
        if ($summary->facilityId !== $facilityId) {
            return 'Appointment is not for the active facility.';
        }

        if (in_array($summary->status, ['ARRIVED', 'IN_SERVICE'], true)) {
            return null;
        }

        if (! in_array($summary->status, ['BOOKED', 'CONFIRMED'], true)) {
            return 'This appointment cannot be checked in from its current status.';
        }

        try {
            $schedulingApi->recordArrival($appointmentId);
        } catch (AuthorizationException) {
            return 'Not authorized to record appointment arrival.';
        } catch (\DomainException $e) {
            return $e->getMessage();
        }

        return null;
    }

    private function queueRoutingError(CheckInEncounterRequest $request, string $message): RedirectResponse
    {
        $returnTo = (string) $request->input('return_to', '');
        $errors = ['queue_id' => $message];

        if (str_starts_with($returnTo, '/') && ! str_starts_with($returnTo, '//')) {
            return redirect($returnTo)->withErrors($errors);
        }

        return back()->withErrors($errors);
    }
}
