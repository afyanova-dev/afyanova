<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Query;

use App\Domains\HR\Domain\Model\LeaveType;
use App\Shared\Bus\ProtectedQuery;

#[ProtectedQuery(ability: 'view', subjectType: LeaveType::class)]
final readonly class GetLeaveTypes
{
    public function __construct(
        public bool $onlyActive = true,
    ) {}
}
