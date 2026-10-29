<?php

declare(strict_types=1);

namespace App\Shared\Events;

/**
 * Static registry mapping domain event classes to listener classes
 * (IMPLEMENTATION-CONTRACTS.md §7.2, §7.3).
 *
 * Registered in {Domain}ServiceProvider::boot():
 *   EventRegistry::listen(PrescriptionDispensed::class, OnDispensingCompleted::class);
 */
final class EventRegistry
{
    /** @var array<class-string, list<class-string>> */
    private static array $listeners = [];

    /**
     * @param  class-string  $eventClass
     * @param  class-string  $listenerClass
     */
    public static function listen(string $eventClass, string $listenerClass): void
    {
        self::$listeners[$eventClass][] = $listenerClass;
    }

    /**
     * @return list<class-string>
     */
    public static function listeners(string $eventClass): array
    {
        return self::$listeners[$eventClass] ?? [];
    }

    /**
     * @return array<class-string, list<class-string>>
     */
    public static function all(): array
    {
        return self::$listeners;
    }

    public static function flush(): void
    {
        self::$listeners = [];
    }
}
