<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Lifecycle state of a pre-authorization (21-insurance-and-payer-domain.md §58;
 * ADR-202 D-A).
 *
 * The §58 set, collapsed: NOT_REQUIRED (a coverage-rule fact — Ins.3's
 * `authorization_required = false`, not a workflow row) and PENDING (= REQUESTED)
 * are dropped. `EFFECTIVE` = the two states an authorization actually grants
 * cover in; DENIED / EXPIRED / CANCELLED are terminal (the row is never deleted
 * — §64). Backend-only string-constant holder.
 */
final class AuthorizationStatus
{
    public const REQUESTED = 'REQUESTED';

    public const APPROVED = 'APPROVED';

    public const PARTIALLY_APPROVED = 'PARTIALLY_APPROVED';

    public const DENIED = 'DENIED';

    public const EXPIRED = 'EXPIRED';

    public const CANCELLED = 'CANCELLED';

    /** @var list<string> */
    public const ALL = [
        self::REQUESTED,
        self::APPROVED,
        self::PARTIALLY_APPROVED,
        self::DENIED,
        self::EXPIRED,
        self::CANCELLED,
    ];

    /** @var list<string> — grants cover (subject to the validity window). */
    public const EFFECTIVE = [self::APPROVED, self::PARTIALLY_APPROVED];

    /** @var list<string> — may be CANCELLED (§64). */
    public const CANCELLABLE = [self::REQUESTED, self::APPROVED, self::PARTIALLY_APPROVED];

    public static function assert(string $value): string
    {
        $value = strtoupper(trim($value));

        if (! in_array($value, self::ALL, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid authorization status [%s]. Permitted: %s.',
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
