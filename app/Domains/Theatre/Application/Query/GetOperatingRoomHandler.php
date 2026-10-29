<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Query;

use App\Domains\Theatre\Contracts\Dto\OperatingRoomSummary;
use App\Domains\Theatre\Domain\Model\OperatingRoom;
use App\Domains\Theatre\Domain\OperatingRoomReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetOperatingRoomHandler
{
    public function __construct(
        private readonly OperatingRoomReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    public function __invoke(GetOperatingRoom $query): OperatingRoomSummary
    {
        $summary = $this->reader->summary($query->operatingRoomId);

        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetOperatingRoom dispatched without an authenticated user (IC §6.1).');
        }

        if (! in_array('theatre.access', $this->scope->effectivePermissions($user, $summary->facilityId), true)) {
            throw NotFound::aggregate(OperatingRoom::class, $query->operatingRoomId);
        }

        return $summary;
    }
}
