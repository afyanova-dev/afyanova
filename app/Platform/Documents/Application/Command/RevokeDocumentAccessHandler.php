<?php

declare(strict_types=1);

namespace App\Platform\Documents\Application\Command;

use App\Platform\Documents\Domain\Repository\AccessGrantRepositoryInterface;

final class RevokeDocumentAccessHandler
{
    public function __construct(
        private readonly AccessGrantRepositoryInterface $accessGrantRepository,
    ) {}

    public function __invoke(RevokeDocumentAccessCommand $command): void
    {
        $this->accessGrantRepository->delete($command->grantId);
    }
}
