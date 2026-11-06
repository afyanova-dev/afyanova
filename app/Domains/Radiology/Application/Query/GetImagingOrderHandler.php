<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Query;

use App\Domains\Radiology\Contracts\Dto\ImagingOrderSummary;
use App\Domains\Radiology\Domain\ImagingOrderReader;
use App\Domains\Radiology\Domain\Model\ImagingOrder;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetImagingOrderHandler
{
    public function __construct(
        private readonly ImagingOrderReader $reader,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(GetImagingOrder $query): ImagingOrderSummary
    {
        $summary = $this->reader->summary($query->orderId);

        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetImagingOrder dispatched without an authenticated user (IC §6.1).');
        }

        if (! $this->scope->hasFacilityPermissionInContext($user, $this->context, $summary->facilityId, 'radiology.access')) {
            throw NotFound::aggregate(ImagingOrder::class, $query->orderId);
        }

        return $summary;
    }
}
