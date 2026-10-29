<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Query;

use App\Domains\Theatre\Contracts\Dto\SurgicalSpecimenSummary;
use App\Domains\Theatre\Domain\Model\SurgicalSpecimen;
use App\Domains\Theatre\Domain\SurgicalSpecimenReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetSurgicalSpecimenHandler
{
    public function __construct(
        private readonly SurgicalSpecimenReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    public function __invoke(GetSurgicalSpecimen $query): SurgicalSpecimenSummary
    {
        $summary = $this->reader->summary($query->surgicalSpecimenId);

        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetSurgicalSpecimen dispatched without an authenticated user (IC §6.1).');
        }

        if (! in_array('theatre.access', $this->scope->effectivePermissions($user, $summary->facilityId), true)) {
            throw NotFound::aggregate(SurgicalSpecimen::class, $query->surgicalSpecimenId);
        }

        return $summary;
    }
}
