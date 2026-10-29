<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Application\Query;

use App\Domains\BedWard\Domain\Model\Ward;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[ProtectedQuery(ability: 'view', subjectType: Ward::class)]
final readonly class GetWard
{
    public function __construct(public string $wardId)
    {
        if (! Str::isUuid($wardId)) {
            throw new InvalidArgumentException('GetWard::$wardId must be a UUID (IC §3).');
        }
    }
}
