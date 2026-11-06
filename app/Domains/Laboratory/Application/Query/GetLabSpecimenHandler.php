<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Query;

use App\Domains\Laboratory\Contracts\Dto\LabSpecimenSummary;
use App\Domains\Laboratory\Domain\LabSpecimenReader;
use App\Domains\Laboratory\Domain\Model\LabSpecimen;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetLabSpecimenHandler
{
    public function __construct(
        private readonly LabSpecimenReader $reader,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(GetLabSpecimen $query): LabSpecimenSummary
    {
        $summary = $this->reader->summary($query->specimenId);

        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetLabSpecimen dispatched without an authenticated user (IC §6.1).');
        }

        if (! $this->scope->hasFacilityPermissionInContext($user, $this->context, $summary->facilityId, 'laboratory.access')) {
            throw NotFound::aggregate(LabSpecimen::class, $query->specimenId);
        }

        return $summary;
    }
}
