<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Transcribe a paper prescription at the pharmacy window (Pharm.4).
 *
 * Class-level `create` (`pharmacy.prescriptions.accept` — intake into the
 * pharmacy). Creates a RECEIVED stub with paper provenance (prescriber
 * verbatim + transcriber) and NO clinical decision link — never a fake one.
 * The medication snapshot is typed at accept-time (`AcceptTranscription`).
 */
final readonly class TranscribePaperPrescription implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $prescriptionId,
        public string $actorId,
        public string $patientId,
        public string $facilityId,
        string $prescriberName,
        public ?string $transcriptionNote = null,
        public ?string $externalIntakeId = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach ([
            'prescriptionId' => $prescriptionId,
            'actorId' => $actorId,
            'patientId' => $patientId,
            'facilityId' => $facilityId,
        ] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("TranscribePaperPrescription::\${$name} must be a UUID (IC §3).");
            }
            if ($externalIntakeId !== null && ! Str::isUuid($externalIntakeId)) {
                throw new InvalidArgumentException('TranscribePaperPrescription::$externalIntakeId must be a UUID.');
            }
        }

        if (trim($prescriberName) === '' || mb_strlen($prescriberName) > 200) {
            throw new InvalidArgumentException('TranscribePaperPrescription::$prescriberName must be 1–200 characters.');
        }

        if ($transcriptionNote !== null && mb_strlen($transcriptionNote) > 1000) {
            throw new InvalidArgumentException('TranscribePaperPrescription::$transcriptionNote must be at most 1000 characters.');
        }

        $this->prescriberName = trim($prescriberName);
    }

    public string $prescriberName;

    public function policyAbility(): string
    {
        return 'create';
    }

    public function subjectId(): ?string
    {
        return null;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'pharmacy.paper_prescription_transcribed',
            actorId: $this->actorId,
            targetType: 'prescription',
            targetId: $this->prescriptionId,
            metadata: [
                'patient_id' => $this->patientId,
                'facility_id' => $this->facilityId,
                'prescriber_name' => $this->prescriberName,
                'external_intake_id' => $this->externalIntakeId,
            ],
        );
    }
}
