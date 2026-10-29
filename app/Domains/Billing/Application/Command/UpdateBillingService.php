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
 * Change a billing service's display `name` and/or `category`
 * (ADR-188 D-C — `code` is SET-ONCE, never touched here). Per-subject `manage`.
 */
final readonly class UpdateBillingService implements Auditable, AuthorizesAgainst
{
    public ?string $name;

    public ?string $category;

    public function __construct(
        public string $billingServiceId,
        public string $actorId,
        ?string $name = null,
        ?string $category = null,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($billingServiceId) || ! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('UpdateBillingService ids must be UUIDs (IC §3).');
        }

        if ($name !== null && trim($name) === '') {
            throw new InvalidArgumentException('UpdateBillingService::$name, when given, must be non-empty.');
        }

        if ($name === null && $category === null) {
            throw new InvalidArgumentException('UpdateBillingService needs at least one of name / category.');
        }

        $this->name = $name !== null ? trim($name) : null;
        $this->category = $category !== null ? BillingServiceCategory::assert($category) : null;
    }

    public function policyAbility(): string
    {
        return 'manage';
    }

    public function subjectId(): string
    {
        return $this->billingServiceId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'billing.billing_service_updated',
            actorId: $this->actorId,
            targetType: 'billing_service',
            targetId: $this->billingServiceId,
            metadata: array_filter([
                'name_changed' => $this->name !== null ? 'true' : null,
                'category' => $this->category,
            ]),
        );
    }
}
