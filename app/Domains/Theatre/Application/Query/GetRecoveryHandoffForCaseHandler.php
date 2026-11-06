<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Query;

use App\Domains\Theatre\Contracts\Dto\RecoveryHandoffSummary;
use App\Domains\Theatre\Domain\Model\RecoveryHandoff;
use App\Domains\Theatre\Domain\RecoveryHandoffReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetRecoveryHandoffForCaseHandler
{
    public function __construct(
        private readonly RecoveryHandoffReader $reader,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(GetRecoveryHandoffForCase $query): ?RecoveryHandoffSummary
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetRecoveryHandoffForCase dispatched without an authenticated user (IC §6.1).');
        }

        $summary = $this->reader->forCase($query->caseId);

        if ($summary === null) {
            return null; // the #[ProtectedQuery] `view` gate already confirmed theatre.access
        }

        if (! $this->scope->hasFacilityPermissionInContext($user, $this->context, $summary->facilityId, 'theatre.access')) {
            throw NotFound::aggregate(RecoveryHandoff::class, $query->caseId);
        }

        return $summary;
    }
}
