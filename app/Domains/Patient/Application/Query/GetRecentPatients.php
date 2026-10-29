<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application\Query;

use App\Domains\Patient\Domain\Model\Patient;
use App\Shared\Bus\ProtectedQuery;

#[ProtectedQuery(ability: 'view', subjectType: Patient::class)]
final readonly class GetRecentPatients
{
    public function __construct(
        public string $facilityId,
        public int $page = 1,
        public int $perPage = 25,
    ) {}
}
