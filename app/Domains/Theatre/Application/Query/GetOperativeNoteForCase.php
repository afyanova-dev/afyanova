<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Query;

use App\Domains\Theatre\Domain\Model\OperativeNote;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read the operative note for a surgical case, or null if none exists yet
 * (ADR-152 D-G).
 */
#[ProtectedQuery(ability: 'view', subjectType: OperativeNote::class)]
final readonly class GetOperativeNoteForCase
{
    public function __construct(public string $caseId)
    {
        if (! Str::isUuid($caseId)) {
            throw new InvalidArgumentException('GetOperativeNoteForCase::$caseId must be a UUID (IC §3).');
        }
    }
}
