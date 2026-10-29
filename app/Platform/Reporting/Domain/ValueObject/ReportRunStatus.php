<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Domain\ValueObject;

enum ReportRunStatus: string
{
    case REQUESTED = 'REQUESTED';
    case RUNNING = 'RUNNING';
    case COMPLETED = 'COMPLETED';
    case FAILED = 'FAILED';

    public function isTerminal(): bool
    {
        return in_array($this, [self::COMPLETED, self::FAILED], true);
    }
}
