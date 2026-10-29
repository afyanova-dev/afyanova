<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain;

use App\Domains\Theatre\Domain\Model\AnaesthesiaRecord;
use App\Shared\Domain\Repository;

/**
 * Write-side repository port for AnaesthesiaRecord aggregate (ADR-155 D-B).
 */
interface AnaesthesiaRecordRepository extends Repository
{
    public function getById(string $id): AnaesthesiaRecord;

    public function getByIdForUpdate(string $id): AnaesthesiaRecord;

    /** The next amendment sequence number for a record (1-based). */
    public function nextAmendmentSequence(string $anaesthesiaRecordId): int;
}
