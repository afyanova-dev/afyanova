<?php

declare(strict_types=1);

namespace App\Shared\Authorization;

/**
 * The catalogue of every permission key the application defines
 * (IMPLEMENTATION-CONTRACTS.md §12, docs/00-foundation/03-identity-rbac.md
 * §31–§33).
 *
 * Each module contributes its `Authorization/permissions.php` (a `string[]` of
 * keys `{domain}.{resource}.{action}`) from its service provider's `boot()`.
 * A key referenced in code but not registered fails an architecture test and
 * `afyanova:conformance`.
 */
final class PermissionRegistry
{
    /** @var array<string, true> */
    private static array $keys = [];

    /**
     * @param  list<string>  $keys
     */
    public static function add(array $keys): void
    {
        foreach ($keys as $key) {
            self::assertShape($key);
            self::$keys[$key] = true;
        }
    }

    public static function has(string $key): bool
    {
        return isset(self::$keys[$key]);
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        $keys = array_keys(self::$keys);
        sort($keys);

        return $keys;
    }

    public static function flush(): void
    {
        self::$keys = [];
    }

    private static function assertShape(string $key): void
    {
        // L1 coarse access: {domain}.access — L2 action: {domain}.{resource}.{action}
        // (docs/00-foundation/03-identity-rbac.md §66).
        $l1 = (bool) preg_match('/^[a-z][a-z0-9_]*\.access$/', $key);
        $l2 = (bool) preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*\.[a-z][a-z0-9_-]*$/', $key);

        if (! $l1 && ! $l2) {
            throw new \InvalidArgumentException(
                "Permission key '{$key}' must be {domain}.access or {domain}.{resource}.{action} in lower snake case."
            );
        }
    }
}
