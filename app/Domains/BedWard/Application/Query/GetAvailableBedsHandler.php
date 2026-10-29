<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Application\Query;

use App\Domains\BedWard\Contracts\Dto\BedSummary;
use App\Domains\BedWard\Domain\BedReader;
use App\Domains\BedWard\Domain\Model\Bed;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetAvailableBedsHandler
{
    public function __construct(
        private readonly BedReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    /**
     * @return list<BedSummary>
     */
    public function __invoke(GetAvailableBeds $query): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetAvailableBeds dispatched without an authenticated user (IC §6.1).');
        }

        if (! in_array('bed_ward.access', $this->scope->effectivePermissions($user, $query->facilityId), true)) {
            throw NotFound::aggregate(Bed::class, $query->facilityId);
        }

        return $this->reader->availableBeds(
            facilityId: $query->facilityId,
            wardType: $query->wardType !== null ? strtoupper(trim($query->wardType)) : null,
            bedClass: $query->bedClass !== null ? strtoupper(trim($query->bedClass)) : null,
            features: $query->features,
        );
    }
}
