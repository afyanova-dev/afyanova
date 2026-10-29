<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Query;

use App\Domains\HR\Domain\Model\Staff;
use App\Shared\Bus\ProtectedQuery;

#[ProtectedQuery(ability: 'view', subjectType: Staff::class)]
final readonly class SearchStaff
{
    public function __construct(
        public ?string $search = null,
        public ?string $status = null,
        public ?string $cadreId = null,
    ) {}
}
