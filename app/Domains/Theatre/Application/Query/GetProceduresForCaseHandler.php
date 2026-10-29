<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Query;

use App\Domains\Theatre\Contracts\Dto\SurgicalProcedureSummary;
use App\Domains\Theatre\Domain\Model\SurgicalProcedure;
use App\Domains\Theatre\Domain\SurgicalProcedureReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetProceduresForCaseHandler
{
    public function __construct(
        private readonly SurgicalProcedureReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    /**
     * @return list<SurgicalProcedureSummary>
     */
    public function __invoke(GetProceduresForCase $query): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetProceduresForCase dispatched without an authenticated user (IC §6.1).');
        }

        $procedures = $this->reader->forCase($query->caseId);

        if ($procedures === []) {
            return []; // the #[ProtectedQuery] `view` gate already confirmed theatre.access
        }

        if (! in_array('theatre.access', $this->scope->effectivePermissions($user, $procedures[0]->facilityId), true)) {
            throw NotFound::aggregate(SurgicalProcedure::class, $query->caseId);
        }

        return $procedures;
    }
}
