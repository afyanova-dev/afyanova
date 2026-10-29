<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Application\Query;

use App\Domains\BedWard\Domain\Model\Bed;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[ProtectedQuery(ability: 'view', subjectType: Bed::class)]
final readonly class GetBed
{
    public function __construct(public string $bedId)
    {
        if (! Str::isUuid($bedId)) {
            throw new InvalidArgumentException('GetBed::$bedId must be a UUID (IC §3).');
        }
    }
}
