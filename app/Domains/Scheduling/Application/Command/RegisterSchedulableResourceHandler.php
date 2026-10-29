<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Application\Command;

use App\Domains\HR\Contracts\HRApi;
use App\Domains\Organization\Contracts\OrganizationApi;
use App\Domains\Scheduling\Domain\Exception\DuplicateSchedulableResourceException;
use App\Domains\Scheduling\Domain\Exception\ProviderUnavailableException;
use App\Domains\Scheduling\Domain\Model\SchedulableResource;
use App\Domains\Scheduling\Domain\SchedulableResourceRepository;
use App\Domains\Scheduling\Domain\ValueObject\ResourceType;
use App\Shared\Tenancy\TenantContext;
use RuntimeException;

final class RegisterSchedulableResourceHandler
{
    public function __construct(
        private readonly SchedulableResourceRepository $resources,
        private readonly OrganizationApi $orgApi,
        private readonly HRApi $hrApi,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(RegisterSchedulableResource $command): void
    {
        if (! $this->orgApi->facilityExists($command->facilityId)) {
            throw new RuntimeException("Facility [{$command->facilityId}] does not exist.");
        }

        if ($command->resourceType === ResourceType::PROVIDER && ! $this->hrApi->staffExists($command->resourceRef)) {
            throw ProviderUnavailableException::notFound($command->resourceRef);
        }

        if ($this->resources->existsForRef($command->facilityId, $command->resourceType, $command->resourceRef)) {
            throw DuplicateSchedulableResourceException::forRef(
                $command->resourceType->value,
                $command->resourceRef,
                $command->facilityId
            );
        }

        $resource = SchedulableResource::register(
            tenantId: $this->context->tenantId(),
            facilityId: $command->facilityId,
            resourceType: $command->resourceType,
            resourceRef: $command->resourceRef,
            name: $command->name,
            isActive: $command->isActive,
            resourceId: $command->resourceId,
        );

        $this->resources->save($resource);
    }
}
