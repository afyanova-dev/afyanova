<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Domains\Organization\Contracts\OrganizationApi;
use App\Domains\Theatre\Domain\Exception\OperatingRoomReferencesUnknownRoom;
use App\Domains\Theatre\Domain\Model\OperatingRoom;
use App\Domains\Theatre\Domain\OperatingRoomRepository;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Str;

/**
 * Orchestrates `CreateOperatingRoom` (ADR-150 D-C). Validates `org_room_id`
 * via the already-existing `OrganizationApi::roomExists` (ADR-150 D-H —
 * found during implementation, no new Organization API method needed).
 */
final class CreateOperatingRoomHandler
{
    public function __construct(
        private readonly OperatingRoomRepository $rooms,
        private readonly OrganizationApi $organization,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(CreateOperatingRoom $command): OperatingRoom
    {
        if (! $this->organization->roomExists($command->facilityId, $command->orgRoomId)) {
            throw OperatingRoomReferencesUnknownRoom::forRoom($command->facilityId, $command->orgRoomId);
        }

        $room = OperatingRoom::create(
            id: $command->operatingRoomId,
            tenantId: $this->context->tenantId(),
            facilityId: $command->facilityId,
            orgRoomId: $command->orgRoomId,
            label: Str::of($command->label)->trim()->toString(),
        );

        $this->rooms->save($room);

        return $room;
    }
}
