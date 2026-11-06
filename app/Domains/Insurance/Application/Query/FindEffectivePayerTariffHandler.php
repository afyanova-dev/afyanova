<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Contracts\Dto\PayerTariffSummary;
use App\Domains\Insurance\Domain\Model\PayerTariff;
use App\Domains\Insurance\Domain\PayerTariffReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class FindEffectivePayerTariffHandler
{
    public function __construct(
        private readonly PayerTariffReader $reader,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(FindEffectivePayerTariff $query): ?PayerTariffSummary
    {
        if ($query->facilityId !== null) {
            $user = Auth::user();
            if (! $user instanceof User) {
                throw new RuntimeException('FindEffectivePayerTariff dispatched without an authenticated user (IC §6.1).');
            }

            if (! $this->scope->hasFacilityPermissionInContext($user, $this->context, $query->facilityId, 'insurance.access')) {
                throw NotFound::aggregate(PayerTariff::class, $query->facilityId);
            }
        }

        return $this->reader->effectiveTariffForPayer(
            $query->payerId,
            $query->serviceCategory,
            $query->onDate,
            $query->facilityId,
        );
    }
}
