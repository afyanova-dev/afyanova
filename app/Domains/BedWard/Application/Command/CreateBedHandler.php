<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Application\Command;

use App\Domains\BedWard\Domain\BedRepository;
use App\Domains\BedWard\Domain\Model\Bed;
use App\Domains\BedWard\Domain\WardRepository;
use App\Shared\Tenancy\TenantContext;

final class CreateBedHandler
{
    public function __construct(
        private readonly BedRepository $beds,
        private readonly WardRepository $wards,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(CreateBed $command): void
    {
        $ward = $this->wards->getById($command->wardId);

        $tenantId = $this->context->tenantId();

        $bed = Bed::create(
            id: $command->bedId,
            tenantId: $tenantId,
            facilityId: $ward->facility_id,
            wardId: $ward->id,
            roomId: $command->roomId,
            label: $command->label,
            bedClass: strtoupper(trim($command->bedClass)),
            features: $command->features,
        );

        $this->beds->save($bed);
    }
}
