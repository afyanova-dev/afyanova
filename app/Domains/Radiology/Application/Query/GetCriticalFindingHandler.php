<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Query;

use App\Domains\Radiology\Contracts\Dto\RadiologyCriticalFindingSummary;
use App\Domains\Radiology\Domain\Model\RadiologyCriticalFinding;
use App\Domains\Radiology\Domain\RadiologyCriticalFindingReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetCriticalFindingHandler
{
    public function __construct(
        private readonly RadiologyCriticalFindingReader $reader,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(GetCriticalFinding $query): RadiologyCriticalFindingSummary
    {
        $summary = $this->reader->summary($query->criticalFindingId);

        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetCriticalFinding dispatched without an authenticated user (IC §6.1).');
        }

        if (! $this->scope->hasFacilityPermissionInContext($user, $this->context, $summary->facilityId, 'radiology.access')) {
            throw NotFound::aggregate(RadiologyCriticalFinding::class, $query->criticalFindingId);
        }

        return $summary;
    }
}
