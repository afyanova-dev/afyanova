<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application\Command;

use App\Domains\Patient\Domain\Exception\PatientMergeRejected;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Consolidate two confirmed-duplicate patient identities — identity-layer only
 * (10-patient-domain.md §21-§24, §130; ADR-118, ADR-128). The non-surviving
 * Patient becomes MERGED; the surviving Patient is unchanged; one
 * `pat_patient_identity_history` row is written; `PatientMerged` is emitted.
 * No child records are reassociated; no cross-domain reconciliation (each
 * owning domain consumes the event in its own phase).
 *
 * `$reason` AND `$evidence` are both mandatory (ADR-128 M-2/M-8) and land in
 * the identity-history row + audit metadata — never in the PHI-free event.
 * Authorization: `patient.patients.merge`, single authorized actor.
 *
 * Not Auditable via the bus: the audit needs the captured pre-merge status of
 * the non-surviving patient, known only in the handler — so the handler writes
 * the audit (same pattern as RegisterPatient / UpdatePatient).
 */
final readonly class MergePatients implements AuthorizesAgainst
{
    public function __construct(
        public string $survivingPatientId,
        public string $nonSurvivingPatientId,
        public string $actorId,
        public string $reason,
        public string $evidence,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($survivingPatientId)) {
            throw new InvalidArgumentException('MergePatients::$survivingPatientId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($nonSurvivingPatientId)) {
            throw new InvalidArgumentException('MergePatients::$nonSurvivingPatientId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('MergePatients::$actorId must be a UUID (IC §3).');
        }

        if ($survivingPatientId === $nonSurvivingPatientId) {
            throw PatientMergeRejected::cannotMergeWithSelf();
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('MergePatients::$reason is mandatory.');
        }

        if (trim($evidence) === '') {
            throw new InvalidArgumentException('MergePatients::$evidence is mandatory.');
        }
    }

    public function policyAbility(): string
    {
        return 'merge';
    }

    public function subjectId(): string
    {
        return $this->nonSurvivingPatientId; // per-subject — the bus loads the non-surviving patient (IC §5.1)
    }
}
