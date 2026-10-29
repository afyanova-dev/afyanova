<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `AmendResult` was dispatched without a reason, or `CancelResult` without one
 * (14-laboratory-domain.md §61, §63, §137; ADR-160 D-F). A correction /
 * cancellation must record why. Maps to HTTP 409.
 */
final class ResultAmendmentIncomplete extends DomainException
{
    public static function reasonRequired(string $action): self
    {
        return new self(sprintf('%s requires a non-empty reason (§137; ADR-160 D-F).', $action));
    }

    public function errorCode(): string
    {
        return 'result_amendment_incomplete';
    }
}
