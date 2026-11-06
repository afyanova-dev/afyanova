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
 * Orchestrates `GetPatientClaims` (ADR-204).
 */
final class GetPatientClaimsHandler
{
    public function __construct(
        private readonly ClaimReader $reader,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    /**
     * @return list<ClaimSummary>
     */
    public function __invoke(GetPatientClaims $query): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetPatientClaims dispatched without an authenticated user (IC §6.1).');
        }

        $facilityIds = $this->scope->facilityIdsWithPermissionInContext($user, $this->context, 'insurance.access');

        return $this->reader->getPatientClaims($query->patientId, $facilityIds, $query->status);
    }
}
