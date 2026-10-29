<?php

declare(strict_types=1);

namespace App\Platform\Integration\Domain\Enum;

enum ConnectorStatus: string
{
    case DISABLED = 'DISABLED';
    case SANDBOX = 'SANDBOX';
    case ENABLED = 'ENABLED';
    case SUSPENDED = 'SUSPENDED';

    public function isOperable(): bool
    {
        return $this === self::SANDBOX || $this === self::ENABLED;
    }
}
