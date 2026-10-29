<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Query;

use App\Domains\Theatre\Domain\Model\CaseEvent;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * A surgical case's intra-op timeline, `event_at` order (ADR-156 D-E).
 */
#[ProtectedQuery(ability: 'view', subjectType: CaseEvent::class)]
final readonly class GetCaseEvents
{
    public function __construct(public string $caseId)
    {
        if (! Str::isUuid($caseId)) {
            throw new InvalidArgumentException('GetCaseEvents::$caseId must be a UUID (IC §3).');
        }
    }
}
