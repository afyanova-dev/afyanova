<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Contracts\Dto\AdjudicationSummary;
use App\Domains\Insurance\Domain\AdjudicationReader;
use App\Domains\Insurance\Domain\Exception\AdjudicationNotFound;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Orchestrates `GetClaimAdjudication` (ADR-205).
 */
final class GetClaimAdjudicationHandler
{
    public function __construct(
        private readonly AdjudicationReader $reader,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(GetClaimAdjudication $query): ?AdjudicationSummary
    {
        $summary = $this->reader->getByClaimId($query->claimId);
        if ($summary === null) {
            return null;
        }

        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetClaimAdjudication dispatched without an authenticated user (IC §6.1).');
        }

        if (! $this->scope->hasFacilityPermissionInContext($user, $this->context, $summary->facilityId, 'insurance.access')) {
            throw AdjudicationNotFound::id($summary->adjudicationId);
        }

        return $summary;
    }
}
