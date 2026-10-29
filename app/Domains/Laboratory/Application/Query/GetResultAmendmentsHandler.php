<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Query;

use App\Domains\Laboratory\Contracts\Dto\LabResultAmendmentSummary;
use App\Domains\Laboratory\Domain\LabResultReader;
use App\Domains\Laboratory\Domain\Model\LabResult;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetResultAmendmentsHandler
{
    public function __construct(
        private readonly LabResultReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    /**
     * @return list<LabResultAmendmentSummary>
     */
    public function __invoke(GetResultAmendments $query): array
    {
        $result = $this->reader->summary($query->resultId); // NotFound if absent

        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetResultAmendments dispatched without an authenticated user (IC §6.1).');
        }

        if (! in_array('laboratory.access', $this->scope->effectivePermissions($user, $result->facilityId), true)) {
            throw NotFound::aggregate(LabResult::class, $query->resultId);
        }

        return $this->reader->amendments($query->resultId);
    }
}
