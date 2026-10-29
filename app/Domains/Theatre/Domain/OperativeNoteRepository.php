<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain;

use App\Domains\Theatre\Domain\Model\OperativeNote;
use App\Shared\Domain\Repository;

/**
 * Write-side repository port for OperativeNote aggregate (ADR-152 D-C).
 */
interface OperativeNoteRepository extends Repository
{
    public function getById(string $id): OperativeNote;

    public function getByIdForUpdate(string $id): OperativeNote;

    /**
     * The next amendment sequence number for a note (1-based). CC.1's
     * ReferralFeedback stays at 1; operative-note amendments genuinely
     * accumulate (§81).
     */
    public function nextAmendmentSequence(string $operativeNoteId): int;
}
