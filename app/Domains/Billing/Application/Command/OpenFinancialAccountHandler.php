<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Domains\Billing\Domain\Exception\BillingPatientNotFound;
use App\Domains\Billing\Domain\Exception\FinancialAccountAlreadyOpen;
use App\Domains\Billing\Domain\FinancialAccountRepository;
use App\Domains\Billing\Domain\Model\FinancialAccount;
use App\Domains\Billing\Infrastructure\Sequence\FinancialAccountNumberSequence;
use App\Domains\Patient\Contracts\PatientApi;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Orchestrates `OpenFinancialAccount` (ADR-187 D-D).
 *
 * Flow: `PatientApi::patientExists` (fail-closed — `BillingPatientNotFound`) →
 * no non-closed account already exists for (patient, facility)
 * (`hasOpenAccountForPatientAtFacility`; the partial unique index is the race
 * backstop → `FinancialAccountAlreadyOpen`) → allocate `FA-%08d` →
 * `FinancialAccount::open()` (records `FinancialAccountOpened`) → save.
 *
 * `FinancialAccountRepository` is the FIRST repository parameter so the bus
 * resolves `FinancialAccountPolicy` (class-level `open`). `PatientApi` is an
 * existence check only — no PHI crosses (IC §6.2 reference-read carve-out).
 */
final class OpenFinancialAccountHandler
{
    public function __construct(
        private readonly FinancialAccountRepository $accounts,
        private readonly FinancialAccountNumberSequence $numbers,
        private readonly PatientApi $patients,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(OpenFinancialAccount $command): void
    {
        if (! $this->patients->patientExists($command->patientId)) {
            throw BillingPatientNotFound::id($command->patientId);
        }

        if ($this->accounts->hasOpenAccountForPatientAtFacility($command->patientId, $command->facilityId)) {
            throw FinancialAccountAlreadyOpen::forPatientAtFacility($command->patientId, $command->facilityId);
        }

        $now = CarbonImmutable::now();

        $account = FinancialAccount::open(
            id: $command->financialAccountId,
            tenantId: $this->context->tenantId(),
            facilityId: $command->facilityId,
            patientId: $command->patientId,
            accountNumber: $this->numbers->next($this->context->tenantId()),
            currency: $command->currency,
            openedByUserId: $command->actorId,
            occurredAt: $now,
            recordedAt: $now,
        );

        try {
            $this->accounts->save($account);
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'bill_financial_accounts_one_active_per_scope')) {
                throw FinancialAccountAlreadyOpen::forPatientAtFacility($command->patientId, $command->facilityId);
            }
            throw $e;
        }
    }
}
