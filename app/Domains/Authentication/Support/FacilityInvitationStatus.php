<?php

declare(strict_types=1);

namespace App\Domains\Authentication\Support;

/**
 * Explicit state machine for facility setup invitations.
 *
 * The credential row is created once per send. A resend supersedes the
 * previous row instead of rotating its token hash, which preserves history.
 */
final class FacilityInvitationStatus
{
    public const PENDING = 'PENDING';

    public const CONSUMED = 'CONSUMED';

    public const EXPIRED = 'EXPIRED';

    public const REVOKED = 'REVOKED';

    public const SUPERSEDED = 'SUPERSEDED';

    /** @return list<string> */
    public static function open(): array
    {
        return [self::PENDING, self::EXPIRED];
    }

    public static function isOpen(string $status): bool
    {
        return in_array($status, self::open(), true);
    }

    public static function label(string $status): string
    {
        return match ($status) {
            self::PENDING => 'Pending',
            self::CONSUMED => 'Accepted',
            self::EXPIRED => 'Expired',
            self::REVOKED => 'Revoked',
            self::SUPERSEDED => 'Replaced',
            default => ucfirst(strtolower($status)),
        };
    }
}
