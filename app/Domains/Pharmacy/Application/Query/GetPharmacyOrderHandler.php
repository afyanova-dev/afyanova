<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application\Query;

use App\Domains\Pharmacy\Contracts\Dto\PharmacyOrderSummary;
use App\Domains\Pharmacy\Domain\Model\PharmacyOrder;
use App\Domains\Pharmacy\Domain\PharmacyOrderReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetPharmacyOrderHandler
{
    public function __construct(
        private readonly PharmacyOrderReader $reader,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(GetPharmacyOrder $query): PharmacyOrderSummary
    {
        $summary = $this->reader->summary($query->pharmacyOrderId);

        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetPharmacyOrder dispatched without an authenticated user (IC §6.1).');
        }

        if (! $this->scope->hasFacilityPermissionInContext($user, $this->context, $summary->facilityId, 'pharmacy.access')) {
            throw NotFound::aggregate(PharmacyOrder::class, $query->pharmacyOrderId);
        }

        return $summary;
    }
}
