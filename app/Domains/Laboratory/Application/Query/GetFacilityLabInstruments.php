<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Query;

use App\Domains\Laboratory\Domain\Model\LabOrder;
use App\Shared\Bus\ProtectedQuery;

#[ProtectedQuery(ability: 'manageInstruments', subjectType: LabOrder::class)]
final readonly class GetFacilityLabInstruments {}
