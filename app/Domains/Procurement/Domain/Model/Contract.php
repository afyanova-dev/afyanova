<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Model;

use App\Domains\Procurement\Database\Factories\ContractFactory;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * A purchase contract with a supplier (19-procurement-domain.md §40, §42, §248;
 * ADR-176 D-B).
 *
 * Tenant-scoped, NOT facility-scoped (the `Supplier` model — ADR-176 D6).
 * Lifecycle: DRAFT → ACTIVE → {TERMINATED, SUPERSEDED (ADR-178 — `RenewContract`
 * mints a superseding row), EXPIRED (ADR-179 — the `procurement:expire-contracts`
 * sweep once past `expiry_date`)}. §194 (Inv 32) enforcement stays check-at-use
 * in `CreatePurchaseOrderHandler` — the EXPIRED status flip is status-accuracy
 * only. Records NO event (ADR-176 / ADR-178 / ADR-179 D-G — no consumer). A DRAFT
 * contract is IMMUTABLE (terminate + recreate); an ACTIVE contract is mutated in
 * place by `AmendContract` (`amendment_count` / `version` bump + a
 * `pcr_contract_amendments` row); TERMINATED / SUPERSEDED / EXPIRED are terminal.
 * Lines live on `pcr_contract_lines`.
 *
 * @domain      Procurement
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @facility    none — tenant-wide (ADR-176 D6)
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $contract_number
 * @property string $supplier_id
 * @property string $title
 * @property string|null $reason
 * @property CarbonImmutable $effective_date
 * @property CarbonImmutable $expiry_date
 * @property string|null $terms
 * @property string $currency
 * @property string $status
 * @property string|null $supersedes_contract_id
 * @property CarbonImmutable|null $superseded_at
 * @property string|null $superseded_by_user_id
 * @property int $amendment_count
 * @property string|null $termination_reason
 * @property string|null $activated_by_user_id
 * @property CarbonImmutable|null $activated_at
 * @property string|null $terminated_by_user_id
 * @property CarbonImmutable|null $terminated_at
 * @property string $created_by_user_id
 * @property CarbonImmutable|null $occurred_at
 * @property CarbonImmutable|null $recorded_at
 * @property int $version
 */
final class Contract extends AggregateRoot
{
    /** @use HasFactory<ContractFactory> */
    use HasFactory;

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_TERMINATED = 'TERMINATED';

    /** ADR-178 — set by `RenewContract` on the contract it replaces. Terminal. */
    public const STATUS_SUPERSEDED = 'SUPERSEDED';

    /** ADR-179 — set by the `procurement:expire-contracts` sweep once past `expiry_date`. Terminal. */
    public const STATUS_EXPIRED = 'EXPIRED';

    protected $table = 'pcr_contracts';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id',
        'contract_number', 'supplier_id', 'title', 'reason',
        'effective_date', 'expiry_date', 'terms', 'currency',
        'status', 'supersedes_contract_id', 'superseded_at', 'superseded_by_user_id', 'amendment_count',
        'termination_reason',
        'activated_by_user_id', 'activated_at', 'terminated_by_user_id', 'terminated_at',
        'created_by_user_id', 'occurred_at', 'recorded_at',
        'version',
    ];

    protected static function newFactory(): ContractFactory
    {
        return ContractFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_date' => 'immutable_date',
            'expiry_date' => 'immutable_date',
            'activated_at' => 'immutable_datetime',
            'terminated_at' => 'immutable_datetime',
            'superseded_at' => 'immutable_datetime',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'amendment_count' => 'integer',
            'version' => 'integer',
        ];
    }

    public static function create(
        string $id,
        string $tenantId,
        string $contractNumber,
        string $supplierId,
        string $title,
        string $createdByUserId,
        string $effectiveDate,
        string $expiryDate,
        ?string $reason,
        ?string $terms,
        string $currency,
        ?CarbonImmutable $occurredAt = null,
    ): self {
        $now = CarbonImmutable::now();

        return new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'contract_number' => $contractNumber,
            'supplier_id' => $supplierId,
            'title' => trim($title),
            'reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : null,
            'effective_date' => CarbonImmutable::parse($effectiveDate),
            'expiry_date' => CarbonImmutable::parse($expiryDate),
            'terms' => $terms !== null && trim($terms) !== '' ? trim($terms) : null,
            'currency' => strtoupper(trim($currency)),
            'status' => self::STATUS_DRAFT,
            'amendment_count' => 0,
            'created_by_user_id' => $createdByUserId,
            'occurred_at' => $occurredAt ?? $now,
            'recorded_at' => $now,
        ]);
    }

    public function markActive(string $activatedByUserId): void
    {
        $this->status = self::STATUS_ACTIVE;
        $this->activated_by_user_id = $activatedByUserId;
        $this->activated_at = CarbonImmutable::now();
    }

    public function markTerminated(string $terminatedByUserId, string $reason): void
    {
        $this->status = self::STATUS_TERMINATED;
        $this->terminated_by_user_id = $terminatedByUserId;
        $this->terminated_at = CarbonImmutable::now();
        $this->termination_reason = trim($reason) !== '' ? trim($reason) : null;
    }

    /** ADR-178 — `RenewContract` flips the replaced contract. Terminal. Records NO event. */
    public function markSuperseded(string $supersededByUserId): void
    {
        $this->status = self::STATUS_SUPERSEDED;
        $this->superseded_by_user_id = $supersededByUserId;
        $this->superseded_at = CarbonImmutable::now();
    }

    /**
     * ADR-179 — the `procurement:expire-contracts` sweep flips an ACTIVE contract
     * once it is past its `expiry_date`. Terminal. Records NO event; the dirtied
     * `status` bumps `version` on save. No actor (a scheduled system sweep) and
     * no `expired_at` — `expiry_date` already records when it expired.
     */
    public function markExpired(): void
    {
        $this->status = self::STATUS_EXPIRED;
    }

    /**
     * ADR-178 — `AmendContract` bumps this on every change so the `pcr_contracts`
     * row is always dirty and `version` advances (the `PurchaseOrder::recordAmendment`
     * / T.2 lesson). Records NO event.
     */
    public function recordAmendment(): void
    {
        $this->amendment_count = (int) $this->amendment_count + 1;
    }

    /** §194 (Inv 32) — the contract can currently authorise a new PO. */
    public function isUsable(): bool
    {
        return (string) $this->status === self::STATUS_ACTIVE
            && $this->expiry_date->toDateString() >= CarbonImmutable::now()->toDateString();
    }
}
