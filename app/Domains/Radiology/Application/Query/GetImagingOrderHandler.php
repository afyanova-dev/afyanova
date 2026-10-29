<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Query;

use App\Domains\Radiology\Contracts\Dto\ImagingOrderSummary;
use App\Domains\Radiology\Domain\ImagingOrderReader;
use App\Domains\Radiology\Domain\Model\ImagingOrder;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetImagingOrderHandler
{
    public function __construct(
        private readonly ImagingOrderReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    public function __invoke(GetImagingOrder $query): ImagingOrderSummary
    {
        $summary = $this->reader->summary($query->orderId);

        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetImagingOrder dispatched without an authenticated user (IC §6.1).');
        }

        if (! in_array('radiology.access', $this->scope->effectivePermissions($user, $summary->facilityId), true)) {
            throw NotFound::aggregate(ImagingOrder::class, $query->orderId);
        }

        return $summary;
    }
}
