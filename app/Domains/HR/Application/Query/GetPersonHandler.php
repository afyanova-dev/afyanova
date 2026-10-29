<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Query;

use App\Domains\HR\Contracts\Dto\PersonDetail;
use App\Domains\HR\Domain\PersonReader;

final class GetPersonHandler
{
    public function __construct(private readonly PersonReader $reader) {}

    public function __invoke(GetPerson $query): PersonDetail
    {
        return $this->reader->detail($query->personId);
    }
}
