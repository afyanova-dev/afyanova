<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Readiness state of a claim prior to submission (21-insurance-and-payer-domain.md §217, §218;
 * ADR-204).
 *
 *   - READY: All local deterministic checks passed; claim is eligible for submission.
 *   - BLOCKED: Critical validation failed (e.g. inactive coverage, missing authorization).
 *   - MISSING_INFORMATION: Incomplete required fields (e.g. no primary diagnosis code, no lines).
 */
final class ClaimReadinessStatus
{
    public const READY = 'READY';

    public const BLOCKED = 'BLOCKED';

    public const MISSING_INFORMATION = 'MISSING_INFORMATION';

    /** @var list<string> */
    public const ALL = [
        self::READY,
        self::BLOCKED,
        self::MISSING_INFORMATION,
    ];

    public static function assert(string $value): string
    {
        $value = strtoupper(trim($value));

        if (! in_array($value, self::ALL, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid claim readiness status [%s]. Permitted: %s.',
                $value,
                implode(', ', self::ALL),
            ));
        }

        return $value;
    }

    public static function isValid(string $value): bool
    {
        return in_array(strtoupper(trim($value)), self::ALL, true);
    }
}
