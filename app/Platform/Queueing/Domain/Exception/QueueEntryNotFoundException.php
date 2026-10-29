<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Domain\Exception;

use App\Shared\Domain\DomainException;

final class QueueEntryNotFoundException extends DomainException
{
    public function errorCode(): string
    {
        return 'QUEUE_ENTRY_NOT_FOUND';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
