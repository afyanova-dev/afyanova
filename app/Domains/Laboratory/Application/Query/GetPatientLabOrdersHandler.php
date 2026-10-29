<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Query;

use App\Domains\Laboratory\Contracts\Dto\LabOrderSummary;
use App\Domains\Laboratory\Domain\LabOrderReader;
use App\Domains\Laboratory\Domain\Model\LabOrder;
use App\Models\User;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetPatientLabOrdersHandler
{
    public function __construct(
        private readonly LabOrderReader $reader,
    ) {}

    /**
     * @return list<LabOrderSummary>
     */
    public function __invoke(GetPatientLabOrders $query): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetPatientLabOrders dispatched without an authenticated user (IC §6.1).');
        }

        if (! $user->hasPermissionKey('laboratory.access')) {
            throw NotFound::aggregate(LabOrder::class, $query->patientId);
        }

        return $this->reader->forPatient($query->patientId);
    }
}
