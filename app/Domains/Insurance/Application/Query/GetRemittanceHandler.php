<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Contracts\Dto\RemittanceSummary;
use App\Domains\Insurance\Domain\Exception\RemittanceNotFound;
use App\Domains\Insurance\Domain\RemittanceReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Orchestrates `GetRemittance` (ADR-205).
 */
final class GetRemittanceHandler
{
    public function __construct(
        private readonly RemittanceReader $reader,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(GetRemittance $query): RemittanceSummary
    {
        $summary = $this->reader->getById($query->remittanceId);
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetRemittance dispatched without an authenticated user (IC §6.1).');
        }

        if (! $this->scope->hasFacilityPermissionInContext($user, $this->context, $summary->facilityId, 'insurance.access')) {
            throw RemittanceNotFound::id($query->remittanceId);
        }

        return $summary;
    }
}
