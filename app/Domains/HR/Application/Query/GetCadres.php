<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Query;

use App\Domains\HR\Domain\Model\Cadre;
use App\Shared\Bus\ProtectedQuery;

#[ProtectedQuery(ability: 'view', subjectType: Cadre::class)]
final readonly class GetCadres
{
    public function __construct(public ?bool $activeOnly = true) {}
}
