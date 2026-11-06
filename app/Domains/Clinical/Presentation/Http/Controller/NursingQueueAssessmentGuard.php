<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Presentation\Http\Controller;

use App\Platform\Queueing\Contracts\QueueingApi;
use App\Shared\Tenancy\TenantContext;

final readonly class NursingQueueAssessmentGuard
{
    public function __construct(private QueueingApi $queueingApi) {}

    public function unavailableReason(
        TenantContext $context,
        string $patientId,
        string $encounterId,
    ): ?string {
        $facilityId = $context->facilityId();
        if ($facilityId === null || $facilityId === '') {
            return 'An active facility is required to record a nursing assessment.';
        }

        $ticket = $this->queueingApi->latestEncounterQueueEntryForCapability(
            $facilityId,
            $encounterId,
            'TRIAGE',
        );

        if ($ticket === null) {
            return null;
        }

        if ($ticket->patientId !== $patientId) {
            return 'The patient does not match the triage queue ticket for this encounter.';
        }

        if ($ticket->status !== 'IN_SERVICE') {
            return 'Confirm the patient is physically present and start triage service before recording an assessment.';
        }

        return null;
    }
}
