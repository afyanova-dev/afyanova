<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Query;

use App\Domains\HR\Domain\Model\StaffUserLink;
use App\Shared\Bus\ProtectedQuery;

#[ProtectedQuery(ability: 'view', subjectType: StaffUserLink::class)]
final readonly class GetUserByStaff
{
    public function __construct(public string $staffId) {}
}
