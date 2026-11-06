<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Contracts\Dto\ClaimSummary;
use App\Domains\Insurance\Domain\ClaimReader;
use App\Domains\Insurance\Domain\Exception\ClaimNotFound;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Orchestrates `GetClaim` (ADR-204).
 */
final class GetClaimHandler
{
    public function __construct(
        private readonly ClaimReader $reader,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(GetClaim $query): ClaimSummary
    {
        $summary = $this->reader->getById($query->claimId)
            ?? throw ClaimNotFound::id($query->claimId);

        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetClaim dispatched without an authenticated user (IC §6.1).');
        }

        if (! $this->scope->hasFacilityPermissionInContext($user, $this->context, $summary->facilityId, 'insurance.access')) {
            throw ClaimNotFound::id($query->claimId);
        }

        return $summary;
    }
}
