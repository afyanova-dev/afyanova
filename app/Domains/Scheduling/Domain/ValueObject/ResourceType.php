<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Domain\ValueObject;

/**
 * Types of schedulable resources (22-scheduling-and-appointments-domain.md §28; ADR-111).
 */
enum ResourceType: string
{
    case PROVIDER = 'PROVIDER';
    case ROOM = 'ROOM';
    case EQUIPMENT = 'EQUIPMENT';
}
