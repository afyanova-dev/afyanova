<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A second recovery handoff was initiated for a case that already has one
 * (17-theatre-domain.md §90; ADR-156 D-D — UNIQUE(tenant_id, case_id)). Maps
 * to HTTP 409.
 */
final class RecoveryHandoffAlreadyExistsForCase extends DomainException
{
    public static function forCase(string $caseId): self
    {
        return new self(sprintf('Surgical case [%s] already has a recovery handoff.', $caseId));
    }

    public function errorCode(): string
    {
        return 'recovery_handoff_already_exists_for_case';
    }
}
