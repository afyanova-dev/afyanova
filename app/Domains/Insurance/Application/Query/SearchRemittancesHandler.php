<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Contracts\Dto\RemittanceSummary;
use App\Domains\Insurance\Domain\RemittanceReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Orchestrates `SearchRemittances` (ADR-205).
 */
final class SearchRemittancesHandler
{
    public function __construct(
        private readonly RemittanceReader $reader,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    /**
     * @return list<RemittanceSummary>
     */
    public function __invoke(SearchRemittances $query): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('SearchRemittances dispatched without an authenticated user (IC §6.1).');
        }

        $facilityIds = $this->scope->facilityIdsWithPermissionInContext($user, $this->context, 'insurance.access');

        return $this->reader->search($facilityIds, $query->payerId, $query->status);
    }
}
