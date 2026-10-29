<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Application\Command;

use App\Domains\BedWard\Domain\Model\Room;
use App\Domains\BedWard\Domain\WardRepository;

final class CreateRoomHandler
{
    public function __construct(
        private readonly WardRepository $wards,
    ) {}

    public function __invoke(CreateRoom $command): void
    {
        $ward = $this->wards->getById($command->wardId);

        Room::create([
            'id' => $command->roomId,
            'tenant_id' => $ward->tenant_id,
            'facility_id' => $ward->facility_id,
            'ward_id' => $ward->id,
            'name' => $command->name,
            'room_type' => $command->roomType,
            'isolation_capable' => $command->isolationCapable,
        ]);
    }
}
