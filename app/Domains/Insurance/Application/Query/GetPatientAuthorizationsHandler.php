<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Contracts\Dto\AuthorizationSummary;
use App\Domains\Insurance\Domain\AuthorizationReader;

final class GetPatientAuthorizationsHandler
{
    public function __construct(private readonly AuthorizationReader $reader) {}

    /**
     * @return list<AuthorizationSummary>
     */
    public function __invoke(GetPatientAuthorizations $query): array
    {
        return $this->reader->forPatient($query->patientId);
    }
}
