<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Domain\Exception;

use App\Shared\Domain\DomainException;

final class MissingOrderProvenanceException extends DomainException
{
    public function errorCode(): string
    {
        return 'MISSING_ORDER_PROVENANCE';
    }
}
