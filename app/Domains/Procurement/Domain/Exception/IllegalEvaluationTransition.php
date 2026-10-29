<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A quotation-evaluation command was dispatched against an evaluation in a
 * status that does not permit it (19-procurement-domain.md §36; ADR-174 D-B).
 * Maps to HTTP 409.
 */
final class IllegalEvaluationTransition extends DomainException
{
    public static function fromStatus(string $action, string $status): self
    {
        return new self(sprintf('%s is not legal from evaluation status [%s].', $action, $status));
    }

    public function errorCode(): string
    {
        return 'illegal_evaluation_transition';
    }
}
