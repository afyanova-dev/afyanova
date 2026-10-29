<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Query;

use App\Domains\Clinical\Domain\Model\PrescribingDecision;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[ProtectedQuery(ability: 'view', subjectType: PrescribingDecision::class)]
final readonly class GetPrescribingDecision
{
    public function __construct(public string $prescribingDecisionId)
    {
        if (! Str::isUuid($prescribingDecisionId)) {
            throw new InvalidArgumentException('GetPrescribingDecision::$prescribingDecisionId must be a UUID (IC §3).');
        }
    }
}
