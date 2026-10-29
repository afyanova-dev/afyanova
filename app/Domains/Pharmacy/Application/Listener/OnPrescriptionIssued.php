<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application\Listener;

use App\Domains\Clinical\Contracts\Event\PrescriptionIssued;
use App\Domains\Pharmacy\Application\Command\IngestPrescription;
use App\Shared\Bus\CommandBus;
use Illuminate\Support\Str;

/**
 * Reacts to Clinical's prescribing decision and creates the Pharmacy-owned
 * Prescription record (16-pharmacy-domain.md §6, §19; ADR-136; ADR-181 D-B).
 *
 * The 2nd event listener in the engagement (the `OnStockReceived` / IC §7.3
 * shape verbatim). The `OutboxRelay` hydrates the event and dispatches a queued
 * `DispatchListenerJob` (a `TenantAwareJob`) that re-establishes the tenant
 * context and invokes this plain invokable. It does exactly one thing: map the
 * event to the `IngestPrescription` system command, keyed for idempotency on the
 * event's `prescribingDecisionId`. All work lives in the handler.
 *
 * The command carries ONLY the PHI-minimal event fields — the medication /
 * dose / quantity snapshot is fetched later by the authenticated
 * `AcceptPrescription` (ADR-181 C1).
 */
final class OnPrescriptionIssued
{
    public function __construct(private readonly CommandBus $bus) {}

    public function __invoke(PrescriptionIssued $event): void
    {
        $this->bus->dispatch(new IngestPrescription(
            prescriptionId: (string) Str::uuid7(),
            prescribingDecisionId: $event->prescribingDecisionId,
            patientId: $event->patientId,
            encounterId: $event->encounterId,
            facilityId: $event->facilityId,
            authorUserId: $event->authorUserId,
            medicationCodeSystem: $event->medicationCodeSystem,
            route: $event->route,
            urgency: $event->urgency,
            changeType: $event->changeType,
            occurredAt: $event->occurredAt,
            idempotencyKey: $event->prescribingDecisionId,
        ));
    }
}
