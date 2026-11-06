<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Query;

use App\Domains\Laboratory\Domain\Model\LabExecution;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[ProtectedQuery(ability: 'view', subjectType: LabExecution::class)]
final readonly class GetLabExecution
{
    public function __construct(public string $executionId)
    {
        if (! Str::isUuid($executionId)) {
            throw new InvalidArgumentException('GetLabExecution::$executionId must be a UUID.');
        }
    }
}
