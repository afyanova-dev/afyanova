<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Domains\Billing\Domain\ValueObject\BillingServiceCategory;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Add a billable service to the catalog (20-billing-and-payments-domain.md §13,
 * §236; ADR-188 D-C). Class-level `manage` (`billing.catalog.manage`).
 *
 * `code` is SET-ONCE (immutable after this command); tenant-unique →
 * `BillingServiceCodeTaken` on the race. §13 — a billing admin authors it.
 */
final readonly class AddBillingService implements Auditable, AuthorizesAgainst
{
    public string $code;

    public string $name;

    public string $category;

    public function __construct(
        public string $billingServiceId,
        public string $actorId,
        string $code,
        string $name,
        string $category,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($billingServiceId) || ! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('AddBillingService ids must be UUIDs (IC §3).');
        }

        $code = trim($code);
        if ($code === '' || mb_strlen($code) > 64) {
            throw new InvalidArgumentException('AddBillingService::$code must be 1–64 characters.');
        }

        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('AddBillingService::$name is required.');
        }

        $this->code = $code;
        $this->name = $name;
        $this->category = BillingServiceCategory::assert($category);
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
            action: 'billing.billing_service_added',
            actorId: $this->actorId,
            targetType: 'billing_service',
            targetId: $this->billingServiceId,
            metadata: ['code' => $this->code, 'category' => $this->category],
        );
    }
}
