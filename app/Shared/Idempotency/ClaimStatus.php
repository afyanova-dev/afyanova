<?php

declare(strict_types=1);

namespace App\Shared\Idempotency;

/**
 * Status of an idempotency claim (IMPLEMENTATION-CONTRACTS.md §5, §14).
 */
enum ClaimStatus: string
{
    case Claimed = 'claimed';
    case Completed = 'completed';
    case InProgress = 'in_progress';
}
