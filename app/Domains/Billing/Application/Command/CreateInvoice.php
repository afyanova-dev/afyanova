<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Create a DRAFT Invoice grouping the eligible POSTED charges of a Financial
 * Account (20-billing-and-payments-domain.md §34 / §35 / §37 / §39; ADR-190 D-C).
 *
 * Class-level `manage` (`billing.invoices.manage`). `chargeIds` null → all
 * eligible charges for the account (D6); a subset → only those. Eligible =
 * POSTED + not reversed (D7) + not claimed by a live invoice line (INV-3). Zero
 * eligible → `NoChargesToInvoice` (D8). Explicit only — NO `ChargeCreated`
 * consumer (D5).
 */
final readonly class CreateInvoice implements Auditable, AuthorizesAgainst
{
    /** @var list<string>|null */
    public ?array $chargeIds;

    /**
     * @param  list<string>|null  $chargeIds
     */
    public function __construct(
        public string $invoiceId,
        public string $actorId,
        public string $financialAccountId,
        ?array $chargeIds = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['invoiceId' => $invoiceId, 'actorId' => $actorId, 'financialAccountId' => $financialAccountId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("CreateInvoice::\${$name} must be a UUID (IC §3).");
            }
        }

        if ($chargeIds !== null) {
            foreach ($chargeIds as $chargeId) {
                if (! Str::isUuid($chargeId)) {
                    throw new InvalidArgumentException('CreateInvoice::$chargeIds entries must be UUIDs (IC §3).');
                }
            }
            $chargeIds = array_values(array_unique($chargeIds));
        }

        $this->chargeIds = $chargeIds;
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
            action: 'billing.invoice_created',
            actorId: $this->actorId,
            targetType: 'invoice',
            targetId: $this->invoiceId,
            metadata: ['financial_account_id' => $this->financialAccountId],
        );
    }
}
