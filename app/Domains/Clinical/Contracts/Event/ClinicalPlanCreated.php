<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Contracts\Event;

/**
 * A ClinicalPlan was created for a patient (ADR-143 D-R).
 * Written to `shared_outbox_events` in the `CreateClinicalPlan` transaction
 * and relayed asynchronously; idempotent consumers key on `eventId`. NOT broadcast.
 *
 * PHI-MINIMIZED (ADR-136, ADR-143 D-R): identifiers + coded planType + envelope.
 * NO clinical_intent, NO goals, NO planned_actions, NO instructions, NO follow_up.
 */
final readonly class ClinicalPlanCreated
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $patientId,
        public string $encounterId,
        public string $clinicalPlanId,
        public string $authorUserId,
        public string $planType,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId,
    ) {}
}
