<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Query;

use App\Domains\Theatre\Contracts\Dto\SurgicalCaseSummary;
use App\Domains\Theatre\Domain\Model\SurgicalCase;
use App\Domains\Theatre\Domain\SurgicalCaseReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetSurgicalCaseHandler
{
    public function __construct(
        private readonly SurgicalCaseReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    public function __invoke(GetSurgicalCase $query): SurgicalCaseSummary
    {
        $summary = $this->reader->summary($query->caseId);

        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetSurgicalCase dispatched without an authenticated user (IC §6.1).');
        }

        if (! in_array('theatre.access', $this->scope->effectivePermissions($user, $summary->facilityId), true)) {
            throw NotFound::aggregate(SurgicalCase::class, $query->caseId);
        }

        return $summary;
    }
}
