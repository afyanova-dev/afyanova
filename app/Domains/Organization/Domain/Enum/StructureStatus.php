<?php

declare(strict_types=1);

namespace App\Domains\Organization\Domain\Enum;

/** Shared lifecycle for departments / units / rooms — configurable structure (ADR-007). */
enum StructureStatus: string
{
    case Active = 'ACTIVE';
    case Inactive = 'INACTIVE';
    case Decommissioned = 'DECOMMISSIONED';
}
