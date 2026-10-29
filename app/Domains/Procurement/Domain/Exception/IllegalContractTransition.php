<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A contract command was dispatched against a contract in a status that does
 * not permit it (19-procurement-domain.md §40; ADR-176 D-B). Maps to HTTP 409.
 */
final class IllegalContractTransition extends DomainException
{
    public static function fromStatus(string $action, string $currentStatus): self
    {
        return new self(sprintf('%s is not legal from contract status [%s].', $action, $currentStatus));
    }

    public function errorCode(): string
    {
        return 'illegal_contract_transition';
    }
}
