<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use RuntimeException;

/**
 * Base for a violated business rule or an invalid state transition
 * (IMPLEMENTATION-CONTRACTS.md §13, §15). Maps to HTTP 409 by default.
 */
abstract class DomainException extends RuntimeException
{
    /** Machine-readable code for the error envelope (§15). */
    abstract public function errorCode(): string;

    public function httpStatus(): int
    {
        return 409;
    }
}
