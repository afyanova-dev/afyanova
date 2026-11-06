<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Query;

use App\Domains\Laboratory\Contracts\Dto\LabResultSummary;
use App\Domains\Laboratory\Domain\LabOrderReader;
use App\Domains\Laboratory\Domain\LabResultReader;
use App\Domains\Laboratory\Domain\Model\LabResult;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetOrderResultsHandler
{
    public function __construct(
        private readonly LabResultReader $results,
        private readonly LabOrderReader $orders,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    /**
     * @return list<LabResultSummary>
     */
    public function __invoke(GetOrderResults $query): array
    {
        $order = $this->orders->summary($query->orderId); // NotFound if the order is absent

        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetOrderResults dispatched without an authenticated user (IC §6.1).');
        }

        if (! $this->scope->hasFacilityPermissionInContext($user, $this->context, $order->facilityId, 'laboratory.access')) {
            throw NotFound::aggregate(LabResult::class, $query->orderId);
        }

        return $this->results->forOrder($query->orderId);
    }
}
