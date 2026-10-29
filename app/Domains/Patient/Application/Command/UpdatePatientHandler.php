<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application\Command;

use App\Domains\Patient\Domain\PatientRepository;
use App\Shared\Audit\AuditLogger;

/**
 * Applies the demographic patch to the Patient aggregate (ADR-127). The audit
 * is written here, not via the bus WriteAuditRow step, because ADR-127 rule 4
 * requires the previous AND new value of each changed field — a field-level
 * diff of the loaded aggregate that the immutable command cannot supply (the
 * same reason RegisterPatient audits from its handler — A.3).
 */
final class UpdatePatientHandler
{
    public function __construct(
        private readonly PatientRepository $patients,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(UpdatePatient $command): UpdatePatientResult
    {
        $patient = $this->patients->getById($command->patientId); // RLS-scoped; version loaded here drives the optimistic lock
        $patient->assertMutable();                                // ADR-128 M-9 — a MERGED identity is not independently mutable

        $diff = $patient->reviseDemographics($command->changes); // ADR-127; auto-clears is_provisional; records PatientUpdated

        if ($diff['changedFields'] === [] && ! $diff['provisionalCleared']) {
            return new UpdatePatientResult((string) $patient->id, [], false); // no-op patch — nothing written
        }

        $this->patients->save($patient); // versioned UPDATE + PatientUpdated -> outbox (IC §7.2, §9.1)

        $this->audit->record(
            action: 'patient.updated',
            actorId: $command->actorId,
            targetType: 'patient',
            targetId: (string) $patient->id,
            reason: $command->reason,
            metadata: [
                'changed_fields' => $diff['changedFields'],
                'previous' => $diff['previous'],
                'new' => $diff['new'],
                'provisional_cleared' => $diff['provisionalCleared'],
            ],
        );

        return new UpdatePatientResult((string) $patient->id, $diff['changedFields'], $diff['provisionalCleared']);
    }
}
