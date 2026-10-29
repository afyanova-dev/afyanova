<?php

declare(strict_types=1);

namespace App\Shared\Events;

use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Direct writer for `shared_outbox_events` (IMPLEMENTATION-CONTRACTS.md §7.2; AGENTS.md §28).
 *
 * Called by aggregate repositories inside `save($aggregate)` — atomic with business changes.
 * Table is RLS-enabled.
 */
final class OutboxWriter
{
    public function __construct(private readonly Container $container) {}

    private function context(): TenantContext
    {
        return $this->container->make(TenantContext::class);
    }

    public function write(object $event): void
    {
        $tenantId = property_exists($event, 'tenantId') && $event->tenantId !== null
            ? (string) $event->tenantId
            : ($this->context()->hasTenant() ? $this->context()->tenantId() : null);

        if ($tenantId === null) {
            throw new \RuntimeException('Cannot write outbox event without tenant context.');
        }

        $id = property_exists($event, 'eventId') && $event->eventId !== null
            ? (string) $event->eventId
            : (string) Str::uuid7();

        $correlationId = property_exists($event, 'correlationId') && $event->correlationId !== null
            ? (string) $event->correlationId
            : CorrelationId::get();

        $causationId = property_exists($event, 'causationId') && $event->causationId !== null
            ? (string) $event->causationId
            : Causation::get();

        $eventClass = get_class($event);
        $eventName = $this->deriveEventName($eventClass);
        $now = now();

        DB::table('shared_outbox_events')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'event_name' => $eventName,
            'event_class' => $eventClass,
            'payload' => json_encode($event, JSON_THROW_ON_ERROR),
            'correlation_id' => $correlationId,
            'causation_id' => $causationId,
            'created_at' => $now,
            'available_at' => $now,
            'published_at' => null,
            'attempts' => 0,
            'last_error' => null,
        ]);
    }

    /**
     * @param  list<object>  $events
     */
    public function writeAll(array $events): void
    {
        foreach ($events as $event) {
            $this->write($event);
        }
    }

    private function deriveEventName(string $eventClass): string
    {
        // App\Domains\Pharmacy\Contracts\Event\PrescriptionDispensed -> pharmacy.PrescriptionDispensed
        // App\Domains\BedWard\Contracts\Event\BedCreated -> bed_ward.BedCreated
        if (preg_match('/App\\\\(?:Domains|Platform)\\\\([^\\\\]+)\\\\Contracts\\\\Event\\\\(.+)$/', $eventClass, $matches)) {
            return $this->snakeCase($matches[1]).'.'.$matches[2];
        }

        return class_basename($eventClass);
    }

    /**
     * PascalCase module segment -> snake_case, matching the permission-key domain
     * segment convention (e.g. `BedWard` -> `bed_ward`). A no-op for every
     * single-word module name (`Clinical` -> `clinical`), so this is
     * byte-for-byte backward compatible with every event name emitted before
     * ADR-148 (the first multi-word module) was built.
     */
    private function snakeCase(string $value): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $value));
    }
}
