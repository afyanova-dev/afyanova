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

final class GetPatientImagingOrdersHandler
{
    public function __construct(
        private readonly ImagingOrderReader $reader,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    /**
     * @return list<ImagingOrderSummary>
     */
    public function __invoke(GetPatientImagingOrders $query): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetPatientImagingOrders dispatched without an authenticated user (IC §6.1).');
        }

        if (! $user->hasPermissionKey('radiology.access')) {
            throw NotFound::aggregate(ImagingOrder::class, $query->patientId);
        }

        $facilityIds = $this->scope->facilityIdsWithPermissionInContext(
            $user,
            $this->context,
            'radiology.access',
        );

        if ($facilityIds === []) {
            throw NotFound::aggregate(ImagingOrder::class, $query->patientId);
        }

        return $this->reader->forPatient($query->patientId, $facilityIds);
    }
}
