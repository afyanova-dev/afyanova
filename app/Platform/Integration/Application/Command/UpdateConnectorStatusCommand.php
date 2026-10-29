<?php

declare(strict_types=1);

namespace App\Platform\Integration\Application\Command;

use App\Platform\Integration\Domain\Enum\ConnectorStatus;

final readonly class UpdateConnectorStatusCommand
{
    public function __construct(
        public string $connectorId,
        public ConnectorStatus $status,
    ) {}
}
