<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application\Query;

use App\Domains\Pharmacy\Contracts\Dto\PickupSummary;
use App\Domains\Pharmacy\Domain\Model\PharmacyOrder;
use App\Domains\Pharmacy\Domain\PickupReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class SearchPickupsHandler
{
    public function __construct(
        private readonly PickupReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    /**
     * @return list<PickupSummary>
     */
    public function __invoke(SearchPickups $query): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('SearchPickups dispatched without an authenticated user (IC §6.1).');
        }

        if (! $this->scope->hasFacilityPermission($user, $query->facilityId, 'pharmacy.access')) {
            throw NotFound::aggregate(PharmacyOrder::class, $query->facilityId);
        }

        return $this->reader->forFacility(
            $query->facilityId,
            $query->status !== null ? strtoupper(trim($query->status)) : null,
        );
    }
}
