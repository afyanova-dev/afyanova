<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\CadreRepository;
use App\Domains\HR\Domain\Exception\DuplicatePositionCodeException;
use App\Domains\HR\Domain\Model\Position;
use App\Domains\HR\Domain\PositionRepository;
use App\Shared\Tenancy\TenantContext;

/**
 * Handles DefinePosition command (ADR-206).
 */
final class DefinePositionHandler
{
    public function __construct(
        private readonly PositionRepository $positions,
        private readonly CadreRepository $cadres,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(DefinePosition $command): void
    {
        if ($this->positions->existsByCode($command->code)) {
            throw DuplicatePositionCodeException::forCode($command->code);
        }

        // Assert cadre exists
        $this->cadres->getById($command->cadreId);

        $position = Position::create(
            tenantId: $this->context->tenantId(),
            code: $command->code,
            title: $command->title,
            cadreId: $command->cadreId,
            description: $command->description,
            isActive: $command->isActive,
        );
        $position->id = $command->positionId;

        $this->positions->save($position);
    }
}
