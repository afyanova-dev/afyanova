<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application\Query;

use App\Domains\Pharmacy\Contracts\Dto\PrescriptionSummary;
use App\Domains\Pharmacy\Domain\Model\Prescription;
use App\Domains\Pharmacy\Domain\PrescriptionReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetPrescriptionHandler
{
    public function __construct(
        private readonly PrescriptionReader $reader,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(GetPrescription $query): PrescriptionSummary
    {
        $summary = $this->reader->summary($query->prescriptionId);

        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetPrescription dispatched without an authenticated user (IC §6.1).');
        }

        if (! $this->scope->hasFacilityPermissionInContext($user, $this->context, $summary->facilityId, 'pharmacy.access')) {
            throw NotFound::aggregate(Prescription::class, $query->prescriptionId);
        }

        return $summary;
    }
}
