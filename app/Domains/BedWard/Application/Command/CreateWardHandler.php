<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Application\Command;

use App\Domains\BedWard\Domain\Exception\WardCapabilityUnavailable;
use App\Domains\BedWard\Domain\Model\Ward;
use App\Domains\BedWard\Domain\WardRepository;
use App\Shared\Capability\FacilityCapabilities;
use App\Shared\Tenancy\TenantContext;

final class CreateWardHandler
{
    public function __construct(
        private readonly WardRepository $wards,
        private readonly FacilityCapabilities $capabilities,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(CreateWard $command): void
    {
        $normalizedType = strtoupper(trim($command->wardType));
        $capabilityCode = match ($normalizedType) {
            'ICU' => 'ICU',
            'MATERNITY' => 'MATERNITY',
            default => 'INPATIENT_CARE',
        };

        if (! $this->capabilities->has($command->facilityId, $capabilityCode)) {
            throw WardCapabilityUnavailable::forWard($command->facilityId, $capabilityCode);
        }

        $tenantId = $this->context->tenantId();

        $ward = Ward::create(
            id: $command->wardId,
            tenantId: $tenantId,
            facilityId: $command->facilityId,
            code: $command->code,
            name: $command->name,
            wardType: $normalizedType,
            capacity: $command->capacity,
            capabilityLink: $command->capabilityLink ?? $capabilityCode,
        );

        $this->wards->save($ward);
    }
}
