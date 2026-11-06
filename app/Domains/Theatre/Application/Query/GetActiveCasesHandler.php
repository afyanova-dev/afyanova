<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Query;

use App\Domains\Theatre\Contracts\Dto\SurgicalCaseSummary;
use App\Domains\Theatre\Domain\Model\SurgicalCase;
use App\Domains\Theatre\Domain\SurgicalCaseReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetActiveCasesHandler
{
    public function __construct(
        private readonly SurgicalCaseReader $reader,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    /**
     * @return list<SurgicalCaseSummary>
     */
    public function __invoke(GetActiveCases $query): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetActiveCases dispatched without an authenticated user (IC §6.1).');
        }

        if (! $this->scope->hasFacilityPermissionInContext($user, $this->context, $query->facilityId, 'theatre.access')) {
            throw NotFound::aggregate(SurgicalCase::class, $query->facilityId);
        }

        return $this->reader->activeCases($query->facilityId);
    }
}
