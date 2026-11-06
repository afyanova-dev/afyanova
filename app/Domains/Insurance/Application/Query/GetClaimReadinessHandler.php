<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Contracts\Dto\ClaimReadinessSummary;
use App\Domains\Insurance\Domain\ClaimLineRepository;
use App\Domains\Insurance\Domain\ClaimRepository;
use App\Domains\Insurance\Domain\Exception\ClaimNotFound;
use App\Domains\Insurance\Domain\Service\ClaimReadinessValidator;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Orchestrates `GetClaimReadiness` (ADR-204).
 */
final class GetClaimReadinessHandler
{
    public function __construct(
        private readonly ClaimRepository $claims,
        private readonly ClaimLineRepository $lines,
        private readonly ClaimReadinessValidator $validator,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(GetClaimReadiness $query): ClaimReadinessSummary
    {
        $claim = $this->claims->getById($query->claimId);
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetClaimReadiness dispatched without an authenticated user (IC §6.1).');
        }

        if (! $this->scope->hasFacilityPermissionInContext($user, $this->context, (string) $claim->facility_id, 'insurance.access')) {
            throw ClaimNotFound::id($query->claimId);
        }

        $claimLines = $this->lines->getLinesForClaim($query->claimId);

        $result = $this->validator->validate($claim, $claimLines);

        return new ClaimReadinessSummary(
            claimId: $claim->id,
            readinessStatus: $result['status'],
            isReady: $result['isReady'],
            blockingReasons: $result['blockingReasons'],
            missingInformation: $result['notes']['missing_information'] ?? [],
            checkedAt: $result['notes']['checked_at'],
        );
    }
}
