<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Domain\Exception;

use App\Shared\Domain\DomainException;

final class InvalidQueueStateTransitionException extends DomainException
{
    public function errorCode(): string
    {
        return 'INVALID_QUEUE_STATE_TRANSITION';
    }
}
