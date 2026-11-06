<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Contracts\Dto\ClaimSummary;
use App\Domains\Insurance\Domain\ClaimReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Orchestrates `GetPayerClaims` (ADR-204).
 */
final class GetPayerClaimsHandler
{
    public function __construct(
        private readonly ClaimReader $reader,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    /**
     * @return list<ClaimSummary>
     */
    public function __invoke(GetPayerClaims $query): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetPayerClaims dispatched without an authenticated user (IC §6.1).');
        }

        $facilityIds = $this->scope->facilityIdsWithPermissionInContext($user, $this->context, 'insurance.access');

        return $this->reader->getPayerClaims($query->payerId, $facilityIds, $query->status);
    }
}
