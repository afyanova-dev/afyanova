<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Presentation\Http\DTO;

use App\Domains\CareCoordination\Contracts\Dto\ReferralSummary;
use App\Domains\Patient\Contracts\Dto\PatientSummary;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final readonly class ReceptionReferralItem
{
    public function __construct(
        public string $referralId,
        public string $patientId,
        public string $priority,
        public string $status,
        public ?string $specialtyRequested,
        public string $targetType,
        public ?string $createdAt,
        #[LiteralTypeScriptType('{ patientId: string; patientNumber: string; displayName: string; dateOfBirth: string | null; sex: string; status: string; isProvisional: boolean; } | null')]
        public ?PatientSummary $patient,
    ) {}

    public static function fromSummary(ReferralSummary $summary, ?PatientSummary $patient): self
    {
        return new self(
            referralId: $summary->referralId,
            patientId: $summary->patientId,
            priority: $summary->priority,
            status: $summary->status,
            specialtyRequested: $summary->specialtyRequested,
            targetType: $summary->targetType,
            createdAt: $summary->createdAt,
            patient: $patient,
        );
    }
}
