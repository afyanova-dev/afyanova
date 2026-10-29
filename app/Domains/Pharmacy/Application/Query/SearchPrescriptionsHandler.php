<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application\Query;

use App\Domains\Pharmacy\Contracts\Dto\PrescriptionSummary;
use App\Domains\Pharmacy\Domain\Model\Prescription;
use App\Domains\Pharmacy\Domain\PrescriptionReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class SearchPrescriptionsHandler
{
    public function __construct(
        private readonly PrescriptionReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    /**
     * @return list<PrescriptionSummary>
     */
    public function __invoke(SearchPrescriptions $query): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('SearchPrescriptions dispatched without an authenticated user (IC §6.1).');
        }

        if (! $this->scope->hasFacilityPermission($user, $query->facilityId, 'pharmacy.access')) {
            throw NotFound::aggregate(Prescription::class, $query->facilityId);
        }

        return $this->reader->forFacility(
            $query->facilityId,
            $query->status !== null ? strtoupper(trim($query->status)) : null,
        );
    }
}
