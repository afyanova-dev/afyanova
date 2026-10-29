<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Lifecycle state of a third-party reimbursement claim (21-insurance-and-payer-domain.md §79;
 * ADR-204).
 *
 * States in Ins.7:
 *   - DRAFT: Created from an issued insured invoice; lines attached with line_active = true.
 *   - READY: Passed deterministic readiness validation (§217/§218); ready for submission.
 *   - SUBMITTED: Formally submitted locally; locked as an immutable historical snapshot (Invariant 8).
 *   - SUPERSEDED: Replaced by a successor claim (CLM-v2) via atomic replacement handover. Terminal.
 *   - CANCELLED: Cancelled before submission (DRAFT or READY only); lines deactivated. Terminal.
 *
 * Adjudication and remittance states (ADJUDICATED, REMITTED, SETTLED) belong to Ins.8.
 */
final class ClaimStatus
{
    public const DRAFT = 'DRAFT';

    public const READY = 'READY';

    public const SUBMITTED = 'SUBMITTED';

    public const ADJUDICATED = 'ADJUDICATED';

    public const REJECTED = 'REJECTED';

    public const PENDED = 'PENDED';

    public const REMITTED = 'REMITTED';

    public const SUPERSEDED = 'SUPERSEDED';

    public const CANCELLED = 'CANCELLED';

    /** @var list<string> */
    public const ALL = [
        self::DRAFT,
        self::READY,
        self::SUBMITTED,
        self::ADJUDICATED,
        self::REJECTED,
        self::PENDED,
        self::REMITTED,
        self::SUPERSEDED,
        self::CANCELLED,
    ];

    /** @var list<string> — states in which unsubmitted claims may be cancelled. */
    public const CANCELLABLE = [
        self::DRAFT,
        self::READY,
    ];

    /** @var list<string> — terminal states. */
    public const TERMINAL = [
        self::REMITTED,
        self::SUPERSEDED,
        self::CANCELLED,
    ];

    public static function assert(string $value): string
    {
        $value = strtoupper(trim($value));

        if (! in_array($value, self::ALL, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid claim status [%s]. Permitted: %s.',
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
