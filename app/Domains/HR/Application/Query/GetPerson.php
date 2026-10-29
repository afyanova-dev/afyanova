<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Query;

use App\Domains\HR\Domain\Model\Person;
use App\Shared\Bus\ProtectedQuery;

#[ProtectedQuery(ability: 'view', subjectType: Person::class)]
final readonly class GetPerson
{
    public function __construct(public string $personId) {}
}
