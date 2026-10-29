<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Presentation\Http\DTO;

use App\Domains\Patient\Contracts\Dto\PatientSummary;
use App\Domains\Scheduling\Contracts\Dto\AppointmentSummary;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final readonly class ReceptionAppointmentItem
{
    public function __construct(
        public string $id,
        public string $facilityId,
        public string $appointmentNumber,
        public ?string $slotId,
        public string $patientId,
        public string $resourceId,
        public string $appointmentTypeId,
        public string $scheduledStart,
        public string $scheduledEnd,
        public string $status,
        public ?string $reason,
        #[LiteralTypeScriptType('{ patientId: string; patientNumber: string; displayName: string; dateOfBirth: string | null; sex: string; status: string; isProvisional: boolean; } | null')]
        public ?PatientSummary $patient,
        public ?string $appointmentTypeName = null,
        public ?string $resourceName = null,
    ) {}

    public static function fromSummary(
        AppointmentSummary $summary,
        ?PatientSummary $patient,
        ?string $appointmentTypeName = null,
        ?string $resourceName = null,
    ): self {
        return new self(
            id: $summary->id,
            facilityId: $summary->facilityId,
            appointmentNumber: $summary->appointmentNumber,
            slotId: $summary->slotId,
            patientId: $summary->patientId,
            resourceId: $summary->resourceId,
            appointmentTypeId: $summary->appointmentTypeId,
            scheduledStart: $summary->scheduledStart,
            scheduledEnd: $summary->scheduledEnd,
            status: $summary->status,
            reason: $summary->reason,
            patient: $patient,
            appointmentTypeName: $appointmentTypeName,
            resourceName: $resourceName,
        );
    }
}
