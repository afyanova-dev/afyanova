<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application\Query;

use App\Domains\Pharmacy\Contracts\Dto\PrescriptionSummary;
use App\Domains\Pharmacy\Domain\PrescriptionReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetPatientPrescriptionsHandler
{
    public function __construct(
        private readonly PrescriptionReader $reader,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    /**
     * @return list<PrescriptionSummary>
     */
    public function __invoke(GetPatientPrescriptions $query): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetPatientPrescriptions dispatched without an authenticated user (IC §6.1).');
        }

        $facilityIds = $this->scope->facilityIdsWithPermissionInContext(
            $user,
            $this->context,
            'pharmacy.access',
        );

        return $this->reader->forPatient(
            $query->patientId,
            $query->status !== null ? strtoupper(trim($query->status)) : null,
            $facilityIds,
        );
    }
}
