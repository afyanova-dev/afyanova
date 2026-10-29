<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Presentation\Http\DTO;

use App\Domains\Encounter\Contracts\Dto\EncounterSummary;
use App\Domains\Patient\Contracts\Dto\PatientSummary;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final readonly class ReceptionEncounterItem
{
    public function __construct(
        public string $encounterId,
        public string $encounterNumber,
        public string $patientId,
        public string $facilityId,
        public string $encounterType,
        public string $status,
        public ?string $disposition,
        public ?string $departmentId,
        public ?string $unitId,
        public ?string $arrivedAt,
        public ?string $startedAt,
        public ?string $admittedAt,
        public ?string $completedAt,
        public ?string $createdSource,
        #[LiteralTypeScriptType('{ patientId: string; patientNumber: string; displayName: string; dateOfBirth: string | null; sex: string; status: string; isProvisional: boolean; } | null')]
        public ?PatientSummary $patient,
        public ?string $queueStatus = null,
        public ?string $queueName = null,
        public ?string $ticketNumber = null,
        public ?bool $vitalsRecorded = null,
        public ?bool $triageRecorded = null,
    ) {}

    public static function fromSummary(
        EncounterSummary $summary,
        ?PatientSummary $patient,
        ?string $queueStatus = null,
        ?string $queueName = null,
        ?string $ticketNumber = null,
        ?bool $vitalsRecorded = null,
        ?bool $triageRecorded = null,
    ): self {
        return new self(
            encounterId: $summary->encounterId,
            encounterNumber: $summary->encounterNumber,
            patientId: $summary->patientId,
            facilityId: $summary->facilityId,
            encounterType: $summary->encounterType,
            status: $summary->status,
            disposition: $summary->disposition,
            departmentId: $summary->departmentId,
            unitId: $summary->unitId,
            arrivedAt: $summary->arrivedAt,
            startedAt: $summary->startedAt,
            admittedAt: $summary->admittedAt,
            completedAt: $summary->completedAt,
            createdSource: $summary->createdSource,
            patient: $patient,
            queueStatus: $queueStatus,
            queueName: $queueName,
            ticketNumber: $ticketNumber,
            vitalsRecorded: $vitalsRecorded,
            triageRecorded: $triageRecorded,
        );
    }
}
