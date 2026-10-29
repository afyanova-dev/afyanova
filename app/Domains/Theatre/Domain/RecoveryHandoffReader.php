<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain;

use App\Domains\Theatre\Contracts\Dto\RecoveryHandoffSummary;

/**
 * Read-side port for RecoveryHandoff queries (ADR-156 D-D).
 */
interface RecoveryHandoffReader
{
    public function forCase(string $caseId): ?RecoveryHandoffSummary;

    public function summary(string $recoveryHandoffId): RecoveryHandoffSummary;
}
