<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Domains\Billing\Domain\Money;
use App\Domains\Billing\Domain\ValueObject\PaymentMethod;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Record money received against a Financial Account
 * (20-billing-and-payments-domain.md §57; ADR-191 B5-B / B5-C).
 *
 * Class-level `manage` (`billing.payments.manage`). The account must exist and
 * not be CLOSED — a prepayment for a patient with no account opens one first
 * (`OpenFinancialAccount`). Posts ONE `PAYMENT` / `CREDIT` for the full amount
 * (B5-B) + mints one `RCT-%08d` receipt. `RECORDED` only — no gateway states.
 */
final readonly class RecordPayment implements Auditable, AuthorizesAgainst
{
    public string $method;

    /** Validated + normalised to a scale-2 numeric string via {@see Money::of()}. */
    public string $amount;

    public function __construct(
        public string $paymentId,
        public string $actorId,
        public string $financialAccountId,
        string $amount,
        string $method,
        public ?string $paymentReference = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['paymentId' => $paymentId, 'actorId' => $actorId, 'financialAccountId' => $financialAccountId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("RecordPayment::\${$name} must be a UUID (IC §3).");
            }
        }

        $this->method = PaymentMethod::assert($method);

        if (! is_numeric($amount) || bccomp(Money::of($amount), '0', 2) <= 0) {
            throw new InvalidArgumentException('RecordPayment::$amount must be a positive money value (§57).');
        }

        $this->amount = Money::of($amount);
    }

    public function policyAbility(): string
    {
        return 'manage';
    }

    public function subjectId(): ?string
    {
        return null;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'billing.payment_recorded',
            actorId: $this->actorId,
            targetType: 'payment',
            targetId: $this->paymentId,
            metadata: [
                'financial_account_id' => $this->financialAccountId,
                'method' => $this->method,
                'amount' => $this->amount,
            ],
        );
    }
}
