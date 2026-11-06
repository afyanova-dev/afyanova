<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Contracts\Dto\PayerTariffSummary;
use App\Domains\Insurance\Domain\Exception\PayerContractNotFound;
use App\Domains\Insurance\Domain\PayerContractReader;
use App\Domains\Insurance\Domain\PayerTariffReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetContractTariffsHandler
{
    public function __construct(
        private readonly PayerTariffReader $reader,
        private readonly PayerContractReader $contracts,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    /**
     * @return list<PayerTariffSummary>
     */
    public function __invoke(GetContractTariffs $query): array
    {
        $contract = $this->contracts->summary($query->contractId);
        if ($contract->facilityId !== null) {
            $user = Auth::user();
            if (! $user instanceof User) {
                throw new RuntimeException('GetContractTariffs dispatched without an authenticated user (IC §6.1).');
            }

            if (! $this->scope->hasFacilityPermissionInContext($user, $this->context, $contract->facilityId, 'insurance.access')) {
                throw PayerContractNotFound::id($query->contractId);
            }
        }

        return $this->reader->forContract($query->contractId, $query->serviceCategory);
    }
}
