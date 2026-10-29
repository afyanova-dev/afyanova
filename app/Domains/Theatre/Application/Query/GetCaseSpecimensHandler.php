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

final class GetCaseSpecimensHandler
{
    public function __construct(
        private readonly SurgicalSpecimenReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    /**
     * @return list<SurgicalSpecimenSummary>
     */
    public function __invoke(GetCaseSpecimens $query): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetCaseSpecimens dispatched without an authenticated user (IC §6.1).');
        }

        $specimens = $this->reader->forCase($query->caseId);

        if ($specimens === []) {
            return []; // the #[ProtectedQuery] `view` gate already confirmed theatre.access
        }

        if (! in_array('theatre.access', $this->scope->effectivePermissions($user, $specimens[0]->facilityId), true)) {
            throw NotFound::aggregate(SurgicalSpecimen::class, $query->caseId);
        }

        return $specimens;
    }
}
