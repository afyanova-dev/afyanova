<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Domains\Billing\Domain\CashSessionRepository;
use App\Domains\Billing\Domain\Exception\CashSessionAlreadyOpen;
use App\Domains\Billing\Domain\Model\CashSession;
use App\Domains\Billing\Domain\Money;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Orchestrates `OpenCashSession` (ADR-191 C6-A). Pre-check the (cashier,
 * facility) live session → `CashSession::open` → save (on the partial-unique
 * race → `CashSessionAlreadyOpen`). Idempotent on `cashSessionId`.
 */
final class OpenCashSessionHandler
{
    public function __construct(
        private readonly CashSessionRepository $sessions,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(OpenCashSession $command): void
    {
        if ($this->sessions->exists($command->cashSessionId)) {
            return;
        }

        if ($this->sessions->liveForCashierAtFacilityForUpdate($command->actorId, $command->facilityId) !== null) {
            throw CashSessionAlreadyOpen::forCashier($command->actorId, $command->facilityId);
        }

        $now = CarbonImmutable::now();

        $session = CashSession::open(
            id: $command->cashSessionId,
            tenantId: $this->context->tenantId(),
            facilityId: $command->facilityId,
            cashierUserId: $command->actorId,
            openingFloat: Money::of($command->openingFloat),
            currency: 'TZS',
            openedByUserId: $command->actorId,
            occurredAt: $now,
            recordedAt: $now,
        );

        try {
            $this->sessions->save($session);
        } catch (UniqueConstraintViolationException) {
            throw CashSessionAlreadyOpen::forCashier($command->actorId, $command->facilityId);
        }
    }
}
