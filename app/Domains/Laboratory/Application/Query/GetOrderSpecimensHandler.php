<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Query;

use App\Domains\Laboratory\Contracts\Dto\LabSpecimenSummary;
use App\Domains\Laboratory\Domain\LabOrderReader;
use App\Domains\Laboratory\Domain\LabSpecimenReader;
use App\Domains\Laboratory\Domain\Model\LabSpecimen;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetOrderSpecimensHandler
{
    public function __construct(
        private readonly LabSpecimenReader $specimens,
        private readonly LabOrderReader $orders,
        private readonly ScopeChecker $scope,
    ) {}

    /**
     * @return list<LabSpecimenSummary>
     */
    public function __invoke(GetOrderSpecimens $query): array
    {
        $order = $this->orders->summary($query->orderId); // NotFound if the order is absent

        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetOrderSpecimens dispatched without an authenticated user (IC §6.1).');
        }

        if (! in_array('laboratory.access', $this->scope->effectivePermissions($user, $order->facilityId), true)) {
            throw NotFound::aggregate(LabSpecimen::class, $query->orderId);
        }

        return $this->specimens->forOrder($query->orderId);
    }
}
