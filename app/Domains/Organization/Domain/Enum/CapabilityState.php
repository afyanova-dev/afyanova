<?php

declare(strict_types=1);

namespace App\Domains\Organization\Domain\Enum;

/** docs/00-foundation/06-facility-levels-and-capabilities.md §11. */
enum CapabilityState: string
{
    case Available = 'AVAILABLE';
    case Enabled = 'ENABLED';
    case Suspended = 'SUSPENDED';
    case Disabled = 'DISABLED';

    public function isOperational(): bool
    {
        return $this === self::Enabled;
    }
}
