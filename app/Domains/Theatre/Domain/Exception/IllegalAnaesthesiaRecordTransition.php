<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * AnaesthesiaRecord status transition (or a content write after signing) was
 * illegal (17-theatre-domain.md §36–§40; ADR-155 D-C). Maps to HTTP 409.
 */
final class IllegalAnaesthesiaRecordTransition extends DomainException
{
    public static function fromStatus(string $action, string $currentStatus): self
    {
        return new self(sprintf(
            'Cannot perform action [%s] on anaesthesia record currently in status [%s].',
            $action,
            $currentStatus,
        ));
    }

    public function errorCode(): string
    {
        return 'illegal_anaesthesia_record_transition';
    }
}
