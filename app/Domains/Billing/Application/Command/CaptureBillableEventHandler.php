<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Domains\Billing\Application\ChargeAttempt;
use App\Domains\Billing\Domain\BillableEventRepository;
use App\Domains\Billing\Domain\Model\BillableEvent;
use App\Domains\Patient\Contracts\PatientApi;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Orchestrates `CaptureBillableEvent` (ADR-189 D-E).
 *
 * `PatientApi::patientExists` + `quantity > 0` → capture a PENDING
 * `bill_billable_events` row (idempotent on `source_event_id`; a re-delivered
 * event whose row is terminal → no-op, whose row is PENDING → re-attempt) →
 * hand to `ChargeAttempt` (map → tariff → lazy-open the account → post the
 * Charge + the ledger DEBIT → mark CHARGED; or hold PENDING with a reason).
 * All one transaction (the bus's) — a throw rolls back + the listener job
 * retries. Never hard-fails (INV-4).
 */
final class CaptureBillableEventHandler
{
    private const SOURCE_DOMAIN = 'PHARMACY';

    public function __construct(
        private readonly BillableEventRepository $billableEvents,
        private readonly ChargeAttempt $attempt,
        private readonly PatientApi $patients,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(CaptureBillableEvent $command): void
    {
        if (! $this->patients->patientExists($command->patientId)) {
            Log::warning('billing.billable_event.unresolved_patient', [
                'source_event_id' => $command->sourceEventId,
                'patient_id' => $command->patientId,
            ]);

            return;
        }

        if (! is_numeric($command->quantity) || bccomp($command->quantity, '0', 4) <= 0) {
            Log::warning('billing.billable_event.non_positive_quantity', [
                'source_event_id' => $command->sourceEventId,
                'quantity' => $command->quantity,
            ]);

            return;
        }

        // Pre-check on BOTH unique keys (re-delivery on the same event id, or a
        // second event for the same dispensing) — a failed INSERT would abort
        // the whole Postgres transaction, so we must not reach it in the common
        // case (the `OpenFinancialAccountHandler` catch-then-throw discipline).
        $existing = $this->billableEvents->findBySourceEventIdForUpdate($command->sourceEventId)
            ?? $this->billableEvents->findBySourceRefForUpdate(self::SOURCE_DOMAIN, $command->dispensingId);
        if ($existing !== null) {
            if (! $existing->isTerminal()) {
                $this->attempt->attempt($existing, $command->dispensedByUserId);
            }

            return;
        }

        $now = CarbonImmutable::now();

        $event = BillableEvent::capture(
            id: (string) Str::uuid7(),
            tenantId: $this->context->tenantId(),
            facilityId: $command->facilityId,
            sourceDomain: self::SOURCE_DOMAIN,
            sourceEventType: 'MedicationDispensed',
            sourceEventId: $command->sourceEventId,
            sourceRef: $command->dispensingId,
            patientId: $command->patientId,
            itemId: $command->itemId,
            quantity: $command->quantity,
            occurredAt: CarbonImmutable::parse($command->occurredAt),
            recordedAt: $now,
        );

        try {
            $this->billableEvents->save($event);
        } catch (UniqueConstraintViolationException) {
            // A truly-concurrent CaptureBillableEvent won the insert by a hair —
            // its own transaction runs the ATTEMPT. Nothing more to do here (and
            // the aborted transaction cannot be queried).
            return;
        }

        $this->attempt->attempt($event, $command->dispensedByUserId);
    }
}
