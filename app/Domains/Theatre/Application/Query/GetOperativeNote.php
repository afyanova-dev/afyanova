<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Query;

use App\Domains\Theatre\Domain\Model\OperativeNote;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one operative note, including its amendment history (ADR-152 D-G).
 */
#[ProtectedQuery(ability: 'view', subjectType: OperativeNote::class)]
final readonly class GetOperativeNote
{
    public function __construct(public string $operativeNoteId)
    {
        if (! Str::isUuid($operativeNoteId)) {
            throw new InvalidArgumentException('GetOperativeNote::$operativeNoteId must be a UUID (IC §3).');
        }
    }
}
