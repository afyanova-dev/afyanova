<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Query;

use App\Domains\Theatre\Domain\Model\RecoveryHandoff;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read the recovery handoff for a surgical case, or null (ADR-156 D-D).
 */
#[ProtectedQuery(ability: 'view', subjectType: RecoveryHandoff::class)]
final readonly class GetRecoveryHandoffForCase
{
    public function __construct(public string $caseId)
    {
        if (! Str::isUuid($caseId)) {
            throw new InvalidArgumentException('GetRecoveryHandoffForCase::$caseId must be a UUID (IC §3).');
        }
    }
}
