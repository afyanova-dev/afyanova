<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Presentation\Http\Controller;

use App\Domains\Patient\Contracts\Dto\PatientSummary;
use App\Domains\Patient\Contracts\PatientApi;
use App\Models\User;
use App\Platform\Queueing\Domain\Enum\QueueStatus;
use App\Platform\Queueing\Domain\Enum\ServiceCapability;
use App\Platform\Queueing\Domain\Model\QueueEntry;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ClinicianWorkspaceController
{
    public function __invoke(Request $request, TenantContext $context, PatientApi $patientApi): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');
        abort_unless($user->can('queue.ticket.view'), 403, 'You do not have access to service queues.');
        abort_unless($user->can('clinical.access'), 403, 'You do not have access to clinical workspaces.');
        abort_unless($user->can('clinical.consultations.read'), 403, 'You do not have access to clinician consultations.');
        abort_unless($user->can('patient.patients.read'), 403, 'You do not have access to patient records.');

        $entries = QueueEntry::query()
            ->with('queue:id,name,code,service_capability')
            ->where('facility_id', $facilityId)
            ->whereIn('status', [
                QueueStatus::WAITING->value,
                QueueStatus::CALLED->value,
                QueueStatus::IN_SERVICE->value,
            ])
            ->whereHas('queue', static fn ($query) => $query
                ->where('service_capability', ServiceCapability::CONSULTATION->value))
            ->orderBy('created_at')
            ->limit(200)
            ->get();

        $patientIds = $entries->pluck('patient_id')->unique()->values()->all();
        $patients = $patientIds === [] ? [] : $patientApi->getPatientSummaries($patientIds);
        $summaries = [];
        foreach ($entries as $entry) {
            $patient = $patients[$entry->patient_id] ?? null;
            abort_unless($patient instanceof PatientSummary, 409, 'Patient context is unavailable for this queue ticket.');
            $summaries[] = [
                'id' => $entry->id,
                'ticketNumber' => $entry->ticket_number,
                'patientName' => $patient->displayName,
                'patientNumber' => $patient->patientNumber,
                'queue' => $entry->queue?->name ?? 'Consultation',
                'priority' => $entry->priority->value,
                'status' => $entry->status->value,
                'waitMinutes' => max(0, (int) CarbonImmutable::now()->diffInMinutes($entry->created_at, true)),
            ];
        }

        return Inertia::render('Clinician/Index', [
            'entries' => $summaries,
        ]);
    }
}
