<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Domains\Insurance\Domain\Exception\PayerCodeTaken;
use App\Domains\Insurance\Domain\Model\Payer;
use App\Domains\Insurance\Domain\PayerRepository;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Orchestrates `RegisterPayer` (ADR-198 D-G). `existsByCode` pre-check + the
 * `unique (tenant_id, code)` index as the race backstop → `PayerCodeTaken`.
 */
final class RegisterPayerHandler
{
    public function __construct(
        private readonly PayerRepository $payers,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(RegisterPayer $command): void
    {
        if ($this->payers->existsByCode($command->code)) {
            throw PayerCodeTaken::code($command->code);
        }

        $now = CarbonImmutable::now();

        $payer = Payer::register(
            id: $command->payerId,
            tenantId: $this->context->tenantId(),
            code: $command->code,
            name: $command->name,
            payerType: $command->payerType,
            contactName: $command->contactName,
            contactEmail: $command->contactEmail,
            contactPhone: $command->contactPhone,
            createdByUserId: $command->actorId,
            occurredAt: $now,
            recordedAt: $now,
        );

        try {
            $this->payers->save($payer);
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'ins_payers_tenant_id_code_unique')) {
                throw PayerCodeTaken::code($command->code);
            }
            throw $e;
        }
    }
}
