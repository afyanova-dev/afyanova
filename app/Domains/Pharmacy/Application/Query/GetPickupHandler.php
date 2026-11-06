<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application\Query;

use App\Domains\Pharmacy\Contracts\Dto\PickupSummary;
use App\Domains\Pharmacy\Domain\Model\Pickup;
use App\Domains\Pharmacy\Domain\PickupReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetPickupHandler
{
    public function __construct(
        private readonly PickupReader $reader,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(GetPickup $query): PickupSummary
    {
        $summary = $this->reader->summary($query->pickupId);

        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetPickup dispatched without an authenticated user (IC §6.1).');
        }

        if (! $this->scope->hasFacilityPermissionInContext($user, $this->context, $summary->facilityId, 'pharmacy.access')) {
            throw NotFound::aggregate(Pickup::class, $query->pickupId);
        }

        return $summary;
    }
}
