<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Query;

use App\Domains\HR\Domain\Model\Assignment;
use App\Shared\Bus\ProtectedQuery;

#[ProtectedQuery(ability: 'view', subjectType: Assignment::class)]
final readonly class GetActiveAssignments
{
    public function __construct(
        public ?string $facilityId = null,
        public ?string $departmentId = null,
        public ?string $unitId = null,
    ) {}
}
