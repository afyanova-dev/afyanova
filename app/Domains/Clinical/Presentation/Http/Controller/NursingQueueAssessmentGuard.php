<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Presentation\Http\Controller;

use App\Platform\Queueing\Domain\Enum\QueueStatus;
use App\Platform\Queueing\Domain\Enum\ServiceCapability;
use App\Platform\Queueing\Domain\Model\QueueEntry;
use App\Shared\Tenancy\TenantContext;

final class NursingQueueAssessmentGuard
{
    public function unavailableReason(
        TenantContext $context,
        string $patientId,
        string $encounterId,
    ): ?string {
        $facilityId = $context->facilityId();
        if ($facilityId === null || $facilityId === '') {
            return 'An active facility is required to record a nursing assessment.';
        }

        $ticket = QueueEntry::query()
            ->with('queue:id,service_capability')
            ->where('facility_id', $facilityId)
            ->where('encounter_id', $encounterId)
            ->whereHas('queue', static fn ($query) => $query
                ->where('service_capability', ServiceCapability::TRIAGE->value))
            ->orderByDesc('created_at')
            ->first();

        if ($ticket === null) {
            return null;
        }

        if ($ticket->patient_id !== $patientId) {
            return 'The patient does not match the triage queue ticket for this encounter.';
        }

        if ($ticket->status !== QueueStatus::IN_SERVICE) {
            return 'Confirm the patient is physically present and start triage service before recording an assessment.';
        }

        return null;
    }
}
