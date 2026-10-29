<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Query;

use App\Domains\Theatre\Contracts\Dto\OrBoardEntry;
use App\Domains\Theatre\Domain\Model\OperatingRoom;
use App\Domains\Theatre\Domain\OperatingRoomReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetOrBoardHandler
{
    public function __construct(
        private readonly OperatingRoomReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    /**
     * @return list<OrBoardEntry>
     */
    public function __invoke(GetOrBoard $query): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetOrBoard dispatched without an authenticated user (IC §6.1).');
        }

        if (! in_array('theatre.access', $this->scope->effectivePermissions($user, $query->facilityId), true)) {
            throw NotFound::aggregate(OperatingRoom::class, $query->facilityId);
        }

        return $this->reader->orBoard($query->facilityId);
    }
}
