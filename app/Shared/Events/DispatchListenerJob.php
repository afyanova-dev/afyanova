<?php

declare(strict_types=1);

namespace App\Shared\Events;

use App\Shared\Jobs\TenantAwareJob;
use App\Shared\Support\Causation;
use RuntimeException;

/**
 * The queued `{Listener}Job` the {@see OutboxRelay} dispatches for each
 * (listener, event) pair (IMPLEMENTATION-CONTRACTS.md §7.2 — "dispatch a queued
 * `{Listener}Job` (a `TenantAwareJob`)").
 *
 * Listeners themselves stay plain `final class` invokables (§7.3); this wrapper
 * carries the tenant / correlation / causation context, re-establishes it in
 * `handle()` (via {@see TenantAwareJob}), and invokes the listener with the
 * event the relay already hydrated. A listener is idempotent (§7.3) so a
 * redelivered event is a no-op.
 */
final class DispatchListenerJob extends TenantAwareJob
{
    /**
     * @param  class-string  $listenerClass
     */
    public function __construct(
        string $tenantId,
        public readonly string $listenerClass,
        public readonly object $event,
        ?string $facilityId = null,
        ?string $correlationId = null,
        ?string $causationId = null,
    ) {
        parent::__construct($tenantId, $facilityId, $correlationId, $causationId);
    }

    protected function execute(): void
    {
        if (! class_exists($this->listenerClass)) {
            throw new RuntimeException("Listener class [{$this->listenerClass}] does not exist.");
        }

        /** @var callable $listener */
        $listener = app($this->listenerClass);

        // The delivered event is the cause of anything the listener does — a
        // command it dispatches, an audit row, a further outbox event (§19).
        Causation::within($this->causationId, fn () => $listener($this->event));
    }
}
