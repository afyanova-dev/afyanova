<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Query;

use App\Domains\Billing\Contracts\Dto\FinancialAccountSummary;
use App\Domains\Billing\Domain\FinancialAccountReader;
use App\Domains\Billing\Domain\Model\FinancialAccount;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The cashier lookup path: newest non-CLOSED Financial Account for
 * (patient, facility), or null when the patient has no open account at the
 * facility (20-billing-and-payments-domain.md §144–§149; ADR-187).
 *
 * `view` gates on the coarse `billing.access` — the summary carries identity
 * references (patient/facility) plus the account number, never ledger money
 * (balance stays behind `GetOutstandingBalance`).
 */
#[ProtectedQuery(ability: 'view', subjectType: FinancialAccount::class)]
final readonly class GetFinancialAccountByPatient
{
    public function __construct(
        public string $patientId,
        public string $facilityId,
    ) {
        foreach (['patientId' => $patientId, 'facilityId' => $facilityId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("GetFinancialAccountByPatient::\${$name} must be a UUID (IC §3).");
            }
        }
    }
}

final class GetFinancialAccountByPatientHandler
{
    public function __construct(private readonly FinancialAccountReader $reader) {}

    public function __invoke(GetFinancialAccountByPatient $query): ?FinancialAccountSummary
    {
        return $this->reader->activeForPatientFacility($query->patientId, $query->facilityId);
    }
}
