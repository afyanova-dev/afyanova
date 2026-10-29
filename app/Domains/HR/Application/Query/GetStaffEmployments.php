<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Query;

use App\Domains\HR\Domain\Model\Employment;
use App\Shared\Bus\ProtectedQuery;

#[ProtectedQuery(ability: 'view', subjectType: Employment::class)]
final readonly class GetStaffEmployments
{
    public function __construct(public string $staffId) {}
}
