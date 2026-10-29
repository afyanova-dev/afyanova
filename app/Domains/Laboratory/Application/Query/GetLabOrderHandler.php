<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Query;

use App\Domains\Laboratory\Contracts\Dto\LabOrderSummary;
use App\Domains\Laboratory\Domain\LabOrderReader;
use App\Domains\Laboratory\Domain\Model\LabOrder;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetLabOrderHandler
{
    public function __construct(
        private readonly LabOrderReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    public function __invoke(GetLabOrder $query): LabOrderSummary
    {
        $summary = $this->reader->summary($query->orderId);

        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetLabOrder dispatched without an authenticated user (IC §6.1).');
        }

        if (! in_array('laboratory.access', $this->scope->effectivePermissions($user, $summary->facilityId), true)) {
            throw NotFound::aggregate(LabOrder::class, $query->orderId);
        }

        return $summary;
    }
}
