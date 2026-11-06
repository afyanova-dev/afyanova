<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application\Query;

use App\Domains\Pharmacy\Contracts\Dto\DispensingSummary;
use App\Domains\Pharmacy\Domain\DispensingReader;
use App\Domains\Pharmacy\Domain\Model\PharmacyOrder;
use App\Domains\Pharmacy\Domain\PharmacyOrderReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetPharmacyOrderDispensingsHandler
{
    public function __construct(
        private readonly DispensingReader $reader,
        private readonly PharmacyOrderReader $orders,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    /**
     * @return list<DispensingSummary>
     */
    public function __invoke(GetPharmacyOrderDispensings $query): array
    {
        $order = $this->orders->summary($query->pharmacyOrderId);

        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetPharmacyOrderDispensings dispatched without an authenticated user (IC §6.1).');
        }

        if (! $this->scope->hasFacilityPermissionInContext($user, $this->context, $order->facilityId, 'pharmacy.access')) {
            throw NotFound::aggregate(PharmacyOrder::class, $query->pharmacyOrderId);
        }

        return $this->reader->forOrder($query->pharmacyOrderId);
    }
}
