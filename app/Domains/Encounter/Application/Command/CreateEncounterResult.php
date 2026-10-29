<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Application\Command;

/**
 * Result of {@see CreateEncounter}. `final readonly` so the idempotency store
 * can serialize / replay it as the same type (IC §5).
 */
final readonly class CreateEncounterResult
{
    public function __construct(
        public string $encounterId,
        public string $encounterNumber,
    ) {}
}
