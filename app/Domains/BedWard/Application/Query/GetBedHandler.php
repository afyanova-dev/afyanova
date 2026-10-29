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

final class GetBedHandler
{
    public function __construct(
        private readonly BedReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    public function __invoke(GetBed $query): BedSummary
    {
        $summary = $this->reader->summary($query->bedId);

        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetBed dispatched without an authenticated user (IC §6.1).');
        }

        if (! in_array('bed_ward.access', $this->scope->effectivePermissions($user, $summary->facilityId), true)) {
            throw NotFound::aggregate(Bed::class, $query->bedId);
        }

        return $summary;
    }
}
