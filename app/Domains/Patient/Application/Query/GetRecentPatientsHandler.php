<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application\Query;

use App\Domains\Patient\Contracts\Dto\PatientSearchResult;
use App\Domains\Patient\Domain\PatientReader;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class GetRecentPatientsHandler
{
    public function __construct(private readonly PatientReader $reader) {}

    /**
     * @return LengthAwarePaginator<int, PatientSearchResult>
     */
    public function __invoke(GetRecentPatients $query): LengthAwarePaginator
    {
        return $this->reader->recent($query->facilityId, $query->page, $query->perPage);
    }
}
