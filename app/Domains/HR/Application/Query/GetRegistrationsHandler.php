<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Query;

use App\Domains\HR\Contracts\Dto\ProfessionalRegistrationSummary;
use App\Domains\HR\Domain\ProfessionalRegistrationReader;

final class GetRegistrationsHandler
{
    public function __construct(private readonly ProfessionalRegistrationReader $reader) {}

    /**
     * @return list<ProfessionalRegistrationSummary>
     */
    public function __invoke(GetRegistrations $query): array
    {
        return $this->reader->forStaff($query->staffId);
    }
}
