<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\Exception;

use App\Shared\Domain\DomainException;

final class LeaveLedgerEntryNotFoundException extends DomainException
{
    public static function forId(string $id): self
    {
        return new self(sprintf('Leave ledger entry [%s] not found.', $id));
    }

    public function errorCode(): string
    {
        return 'leave_ledger_entry_not_found';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
