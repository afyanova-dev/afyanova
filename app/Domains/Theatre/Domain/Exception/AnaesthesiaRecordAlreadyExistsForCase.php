<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A second anaesthesia record was created for a surgical case that already
 * has one (17-theatre-domain.md §36; ADR-155 D-D — `UNIQUE(tenant_id,
 * case_id)` on `thr_anaesthesia_records`). Maps to HTTP 409.
 */
final class AnaesthesiaRecordAlreadyExistsForCase extends DomainException
{
    public static function forCase(string $caseId): self
    {
        return new self(sprintf(
            'Surgical case [%s] already has an anaesthesia record.',
            $caseId,
        ));
    }

    public function errorCode(): string
    {
        return 'anaesthesia_record_already_exists_for_case';
    }
}
