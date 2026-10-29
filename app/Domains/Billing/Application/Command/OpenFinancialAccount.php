<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Open a Financial Account for a patient at a facility
 * (20-billing-and-payments-domain.md §7, §144–§149; ADR-187 D-D).
 *
 * ONE non-closed account per patient per facility (HD-1; the partial unique
 * index — `FinancialAccountAlreadyOpen` on the race). Class-level authorization
 * (`open`) — the `FinancialAccountPolicy` checks `billing.accounts.manage` at
 * this command's `facilityId`. Single currency `TZS` in Bill.1.
 */
final readonly class OpenFinancialAccount implements Auditable, AuthorizesAgainst
{
    public string $currency;

    public function __construct(
        public string $financialAccountId,
        public string $actorId,
        public string $patientId,
        public string $facilityId,
        string $currency = 'TZS',
        public ?string $idempotencyKey = null,
    ) {
        foreach ([
            'financialAccountId' => $financialAccountId,
            'actorId' => $actorId,
            'patientId' => $patientId,
            'facilityId' => $facilityId,
        ] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("OpenFinancialAccount::\${$name} must be a UUID (IC §3).");
            }
        }

        $currency = strtoupper(trim($currency));
        if ($currency !== 'TZS') {
            throw new InvalidArgumentException('OpenFinancialAccount::$currency must be TZS in Bill.1.');
        }

        $this->currency = $currency;
    }

    public function policyAbility(): string
    {
        return 'open';
    }

    public function subjectId(): ?string
    {
        return null;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'billing.financial_account_opened',
            actorId: $this->actorId,
            targetType: 'financial_account',
            targetId: $this->financialAccountId,
            metadata: [
                'patient_id' => $this->patientId,
                'facility_id' => $this->facilityId,
                'currency' => $this->currency,
            ],
        );
    }
}
