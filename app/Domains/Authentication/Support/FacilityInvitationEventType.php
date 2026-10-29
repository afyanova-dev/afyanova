<?php

declare(strict_types=1);

namespace App\Domains\Authentication\Support;

final class FacilityInvitationEventType
{
    public const ISSUED = 'ISSUED';

    public const RESENT = 'RESENT';

    public const SUPERSEDED = 'SUPERSEDED';

    public const EXPIRED = 'EXPIRED';

    public const REVOKED = 'REVOKED';

    public const CONSUMED = 'CONSUMED';

    public const DELIVERY_SENT = 'DELIVERY_SENT';

    public const DELIVERY_FAILED = 'DELIVERY_FAILED';

    public static function label(string $type): string
    {
        return match ($type) {
            self::ISSUED => 'Invitation sent',
            self::RESENT => 'Invitation resent',
            self::SUPERSEDED => 'Previous link replaced',
            self::EXPIRED => 'Invitation expired',
            self::REVOKED => 'Invitation revoked',
            self::CONSUMED => 'Account setup completed',
            self::DELIVERY_SENT => 'Email delivered',
            self::DELIVERY_FAILED => 'Email delivery failed',
            default => ucfirst(strtolower(str_replace('_', ' ', $type))),
        };
    }
}
