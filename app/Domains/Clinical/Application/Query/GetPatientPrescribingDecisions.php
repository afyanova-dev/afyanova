<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Query;

use App\Domains\Clinical\Domain\Model\PrescribingDecision;
use App\Domains\Clinical\Domain\ValueObject\PrescribingDecisionStatus;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[ProtectedQuery(ability: 'view', subjectType: PrescribingDecision::class)]
final readonly class GetPatientPrescribingDecisions
{
    public function __construct(
        public string $patientId,
        public ?string $status = null,
    ) {
        if (! Str::isUuid($patientId)) {
            throw new InvalidArgumentException('GetPatientPrescribingDecisions::$patientId must be a UUID (IC §3).');
        }

        if ($status !== null && ! PrescribingDecisionStatus::isValid($status)) {
            throw new InvalidArgumentException(sprintf(
                'GetPatientPrescribingDecisions::$status must be a valid PrescribingDecisionStatus or null, got [%s] (ADR-143 D-J).',
                $status,
            ));
        }
    }
}
