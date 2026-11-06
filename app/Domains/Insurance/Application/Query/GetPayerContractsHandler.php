<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Contracts\Dto\PayerContractSummary;
use App\Domains\Insurance\Domain\PayerContractReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetPayerContractsHandler
{
    public function __construct(
        private readonly PayerContractReader $reader,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    /**
     * @return list<PayerContractSummary>
     */
    public function __invoke(GetPayerContracts $query): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetPayerContracts dispatched without an authenticated user (IC §6.1).');
        }

        $facilityIds = $this->scope->facilityIdsWithPermissionInContext($user, $this->context, 'insurance.access');

        return $this->reader->forPayer($query->payerId, $facilityIds, $query->status);
    }
}
