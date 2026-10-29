<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Query;

use App\Domains\Laboratory\Contracts\Dto\LabCriticalResultSummary;
use App\Domains\Laboratory\Domain\LabCriticalResultReader;
use App\Domains\Laboratory\Domain\Model\LabCriticalResult;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetCriticalResultHandler
{
    public function __construct(
        private readonly LabCriticalResultReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    public function __invoke(GetCriticalResult $query): LabCriticalResultSummary
    {
        $summary = $this->reader->summary($query->criticalResultId);

        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetCriticalResult dispatched without an authenticated user (IC §6.1).');
        }

        if (! in_array('laboratory.access', $this->scope->effectivePermissions($user, $summary->facilityId), true)) {
            throw NotFound::aggregate(LabCriticalResult::class, $query->criticalResultId);
        }

        return $summary;
    }
}
