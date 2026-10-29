<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\CadreRepository;
use App\Domains\HR\Domain\Exception\DuplicateCadreCodeException;
use App\Domains\HR\Domain\Model\Cadre;
use App\Shared\Tenancy\TenantContext;

/**
 * Handles DefineCadre command (ADR-206).
 */
final class DefineCadreHandler
{
    public function __construct(
        private readonly CadreRepository $cadres,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(DefineCadre $command): void
    {
        if ($this->cadres->existsByCode($command->code)) {
            throw DuplicateCadreCodeException::forCode($command->code);
        }

        $cadre = Cadre::create(
            tenantId: $this->context->tenantId(),
            code: $command->code,
            name: $command->name,
            category: $command->category,
            description: $command->description,
            isActive: $command->isActive,
        );
        $cadre->id = $command->cadreId;

        $this->cadres->save($cadre);
    }
}
