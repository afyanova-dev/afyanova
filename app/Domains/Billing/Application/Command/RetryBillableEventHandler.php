<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Domains\Billing\Application\ChargeAttempt;
use App\Domains\Billing\Domain\BillableEventRepository;

/**
 * Orchestrates `RetryBillableEvent` (ADR-189 D-G). `getByIdForUpdate` → no-op if
 * not PENDING → `ChargeAttempt::attempt` (a lazy-opened account during a manual
 * retry is attributed to the acting billing admin).
 */
final class RetryBillableEventHandler
{
    public function __construct(
        private readonly BillableEventRepository $billableEvents,
        private readonly ChargeAttempt $attempt,
    ) {}

    public function __invoke(RetryBillableEvent $command): void
    {
        $event = $this->billableEvents->getByIdForUpdate($command->billableEventId);

        if (! $event->isPending()) {
            return;
        }

        $this->attempt->attempt($event, $command->actorId);
    }
}
