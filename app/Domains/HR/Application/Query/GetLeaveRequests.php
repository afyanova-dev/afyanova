<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Query;

use App\Domains\HR\Domain\Model\LeaveRequest;
use App\Shared\Bus\ProtectedQuery;

#[ProtectedQuery(ability: 'view', subjectType: LeaveRequest::class)]
final readonly class GetLeaveRequests
{
    public function __construct(
        public ?string $facilityId = null,
        public ?string $staffId = null,
        public ?string $status = null,
    ) {}
}
