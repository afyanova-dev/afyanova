<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Query;

use App\Domains\HR\Contracts\Dto\StaffDetail;
use App\Domains\HR\Domain\StaffReader;

final class GetStaffDetailHandler
{
    public function __construct(private readonly StaffReader $reader) {}

    public function __invoke(GetStaffDetail $query): StaffDetail
    {
        return $this->reader->detail($query->staffId);
    }
}
