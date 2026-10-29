<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A second operative note was created for a surgical case that already has
 * one (17-theatre-domain.md §77; ADR-152 D-E — `UNIQUE(tenant_id, case_id)`
 * on `thr_operative_notes`). Maps to HTTP 409.
 */
final class OperativeNoteAlreadyExistsForCase extends DomainException
{
    public static function forCase(string $caseId): self
    {
        return new self(sprintf(
            'Surgical case [%s] already has an operative note.',
            $caseId,
        ));
    }

    public function errorCode(): string
    {
        return 'operative_note_already_exists_for_case';
    }
}
