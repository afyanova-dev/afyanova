<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\Exception\DuplicateShiftPatternCodeException;
use App\Domains\HR\Domain\Exception\InvalidOrganizationStructureException;
use App\Domains\HR\Domain\Model\ShiftPattern;
use App\Domains\HR\Domain\ShiftPatternRepository;
use App\Domains\Organization\Contracts\OrganizationApi;
use App\Shared\Tenancy\TenantContext;

/**
 * Handles DefineShiftPattern command (25-hr-and-workforce-domain.md §6; ADR-206).
 */
final class DefineShiftPatternHandler
{
    public function __construct(
        private readonly ShiftPatternRepository $patterns,
        private readonly OrganizationApi $org,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(DefineShiftPattern $command): void
    {
        if (! $this->org->facilityExists($command->facilityId)) {
            throw InvalidOrganizationStructureException::facilityNotFound($command->facilityId);
        }

        $existing = $this->patterns->findByFacilityAndCode($command->facilityId, $command->code);
        if ($existing !== null) {
            throw DuplicateShiftPatternCodeException::forCode($command->code, $command->facilityId);
        }

        $pattern = ShiftPattern::create(
            tenantId: $this->context->tenantId(),
            facilityId: $command->facilityId,
            code: $command->code,
            name: $command->name,
            startTime: $command->startTime,
            endTime: $command->endTime,
            breakMinutes: $command->breakMinutes,
            isOvernight: $command->isOvernight,
            shiftPatternId: $command->shiftPatternId,
        );

        $this->patterns->save($pattern);
    }
}
