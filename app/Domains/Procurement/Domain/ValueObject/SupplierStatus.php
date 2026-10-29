<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\ValueObject;

/**
 * Supplier lifecycle vocabulary (19-procurement-domain.md §12, §14, §15;
 * ADR-171 D-C — F5a).
 *
 * The canonical status list and the allowed transitions live here so the
 * screen, the Form Request, the lifecycle guard and the read model all read the
 * SAME table. A Status VO is presentation-safe (the `ItemType` / `ItemCategory`
 * precedent), which keeps `Domain\Model\Supplier` out of HTTP controllers.
 *
 *   ACTIVE      -> SUSPENDED | INACTIVE | BLACKLISTED
 *   SUSPENDED   -> ACTIVE | INACTIVE | BLACKLISTED
 *   INACTIVE    -> ACTIVE
 *   BLACKLISTED -> (terminal — §15 states no reversibility)
 */
final class SupplierStatus
{
    public const ACTIVE = 'ACTIVE';

    public const SUSPENDED = 'SUSPENDED';

    public const INACTIVE = 'INACTIVE';

    public const BLACKLISTED = 'BLACKLISTED';

    /** @var list<string> */
    public const ALL = [self::ACTIVE, self::SUSPENDED, self::INACTIVE, self::BLACKLISTED];

    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        self::ACTIVE => [self::SUSPENDED, self::INACTIVE, self::BLACKLISTED],
        self::SUSPENDED => [self::ACTIVE, self::INACTIVE, self::BLACKLISTED],
        self::INACTIVE => [self::ACTIVE],
        self::BLACKLISTED => [],
    ];

    /**
     * The statuses a supplier in `$status` may move to. An unknown status has
     * nowhere to go (default deny).
     *
     * @return list<string>
     */
    public static function allowedFrom(string $status): array
    {
        return self::TRANSITIONS[strtoupper(trim($status))] ?? [];
    }

    public static function isKnown(string $status): bool
    {
        return in_array(strtoupper(trim($status)), self::ALL, true);
    }

    public static function isOrderable(string $status): bool
    {
        return strtoupper(trim($status)) === self::ACTIVE;
    }
}
