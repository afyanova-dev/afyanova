<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Query;

use App\Domains\HR\Domain\Model\ProfessionalRegistration;
use App\Shared\Bus\ProtectedQuery;

#[ProtectedQuery(ability: 'view', subjectType: ProfessionalRegistration::class)]
final readonly class GetRegistrations
{
    public function __construct(public string $staffId) {}
}
