<?php

declare(strict_types=1);

namespace App\Platform\Integration\Domain\Enum;

enum FileImportStatus: string
{
    case RECEIVED = 'RECEIVED';
    case PARSING = 'PARSING';
    case TRANSLATING = 'TRANSLATING';
    case DISPATCHED = 'DISPATCHED';
    case RECONCILED = 'RECONCILED';
    case PARTIAL = 'PARTIAL';
    case FAILED = 'FAILED';

    public function isTerminal(): bool
    {
        return $this === self::RECONCILED || $this === self::PARTIAL || $this === self::FAILED;
    }
}
