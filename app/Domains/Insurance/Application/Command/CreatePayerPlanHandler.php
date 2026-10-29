<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Domains\Insurance\Domain\Exception\PayerNotFound;
use App\Domains\Insurance\Domain\Exception\PayerPlanCodeTaken;
use App\Domains\Insurance\Domain\Model\Plan;
use App\Domains\Insurance\Domain\PayerReader;
use App\Domains\Insurance\Domain\PlanRepository;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Orchestrates `CreatePayerPlan` (ADR-198 D-H). Payer-exists check
 * (`PayerNotFound`) → `existsByCode` pre-check + the `unique (tenant_id,
 * payer_id, code)` index backstop → `PayerPlanCodeTaken`.
 */
final class CreatePayerPlanHandler
{
    public function __construct(
        private readonly PlanRepository $plans,
        private readonly PayerReader $payers,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(CreatePayerPlan $command): void
    {
        if (! $this->payers->exists($command->payerId)) {
            throw PayerNotFound::id($command->payerId);
        }

        if ($this->plans->existsByCode($command->payerId, $command->code)) {
            throw PayerPlanCodeTaken::code($command->payerId, $command->code);
        }

        $now = CarbonImmutable::now();

        $plan = Plan::create(
            id: $command->planId,
            tenantId: $this->context->tenantId(),
            payerId: $command->payerId,
            code: $command->code,
            name: $command->name,
            effectiveFrom: $command->effectiveFrom,
            effectiveTo: $command->effectiveTo,
            createdByUserId: $command->actorId,
            occurredAt: $now,
            recordedAt: $now,
        );

        try {
            $this->plans->save($plan);
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'ins_plans_tenant_id_payer_id_code_unique')) {
                throw PayerPlanCodeTaken::code($command->payerId, $command->code);
            }
            throw $e;
        }
    }
}
