<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Model;

use App\Domains\Insurance\Database\Factories\PayerContractFactory;
use App\Domains\Insurance\Domain\Exception\IllegalPayerContractTransition;
use App\Domains\Insurance\Domain\ValueObject\PayerContractStatus;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * The provider-payer contract agreement (21-insurance-and-payer-domain.md §49, §50,
 * §112, §113, §159; Contract Invariants §275 Inv 20–22; ADR-203).
 *
 * Models the commercial and operational agreement between the healthcare provider
 * (tenant/facility) and a contracted payer. Governs allowed amounts / tariffs.
 *
 * @domain      Insurance
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @facility    optional
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $contract_number
 * @property string $payer_id
 * @property string $name
 * @property string|null $reference
 * @property string|null $facility_id
 * @property string $status
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_to
 * @property string|null $terms
 * @property string|null $termination_reason
 * @property string $created_by_user_id
 * @property string|null $activated_by_user_id
 * @property CarbonImmutable|null $activated_at
 * @property string|null $terminated_by_user_id
 * @property CarbonImmutable|null $terminated_at
 * @property CarbonImmutable|null $expired_at
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $recorded_at
 * @property int $version
 */
final class PayerContract extends AggregateRoot
{
    /** @use HasFactory<PayerContractFactory> */
    use HasFactory;

    protected $table = 'ins_payer_contracts';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id',
        'contract_number', 'payer_id', 'name', 'reference', 'facility_id',
        'status', 'effective_from', 'effective_to',
        'terms', 'termination_reason',
        'created_by_user_id',
        'activated_by_user_id', 'activated_at',
        'terminated_by_user_id', 'terminated_at',
        'expired_at',
        'occurred_at', 'recorded_at',
        'version',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'effective_from' => 'immutable_date',
        'effective_to' => 'immutable_date',
        'activated_at' => 'immutable_datetime',
        'terminated_at' => 'immutable_datetime',
        'expired_at' => 'immutable_datetime',
        'occurred_at' => 'immutable_datetime',
        'recorded_at' => 'immutable_datetime',
        'version' => 'integer',
    ];

    protected static function newFactory(): PayerContractFactory
    {
        return PayerContractFactory::new();
    }

    public static function createDraft(
        string $id,
        string $tenantId,
        string $contractNumber,
        string $payerId,
        string $name,
        ?string $reference,
        ?string $facilityId,
        CarbonImmutable $effectiveFrom,
        ?CarbonImmutable $effectiveTo,
        ?string $terms,
        string $createdByUserId,
        CarbonImmutable $now,
    ): self {
        $contract = new self;
        $contract->id = $id;
        $contract->tenant_id = $tenantId;
        $contract->contract_number = $contractNumber;
        $contract->payer_id = $payerId;
        $contract->name = $name;
        $contract->reference = $reference !== null ? trim($reference) : null;
        $contract->facility_id = $facilityId;
        $contract->status = PayerContractStatus::DRAFT;
        $contract->effective_from = $effectiveFrom;
        $contract->effective_to = $effectiveTo;
        $contract->terms = $terms !== null ? trim($terms) : null;
        $contract->created_by_user_id = $createdByUserId;
        $contract->occurred_at = $now;
        $contract->recorded_at = $now;
        $contract->version = 0;

        return $contract;
    }

    public function activate(string $actorId, CarbonImmutable $now): void
    {
        if ($this->status !== PayerContractStatus::DRAFT) {
            throw IllegalPayerContractTransition::cannotTransition(
                $this->id,
                $this->status,
                PayerContractStatus::ACTIVE,
            );
        }

        $this->status = PayerContractStatus::ACTIVE;
        $this->activated_by_user_id = $actorId;
        $this->activated_at = $now;
        $this->occurred_at = $now;
        $this->recorded_at = $now;
    }

    public function terminate(string $actorId, string $reason, CarbonImmutable $now): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('A non-empty termination reason is required.');
        }

        if ($this->status !== PayerContractStatus::ACTIVE) {
            throw IllegalPayerContractTransition::cannotTransition(
                $this->id,
                $this->status,
                PayerContractStatus::TERMINATED,
            );
        }

        $this->status = PayerContractStatus::TERMINATED;
        $this->terminated_by_user_id = $actorId;
        $this->terminated_at = $now;
        $this->termination_reason = $reason;
        $this->occurred_at = $now;
        $this->recorded_at = $now;
    }

    public function expire(CarbonImmutable $now): void
    {
        if ($this->status === PayerContractStatus::EXPIRED || $this->status === PayerContractStatus::TERMINATED) {
            return;
        }

        if ($this->status !== PayerContractStatus::ACTIVE) {
            throw IllegalPayerContractTransition::cannotTransition(
                $this->id,
                $this->status,
                PayerContractStatus::EXPIRED,
            );
        }

        $this->status = PayerContractStatus::EXPIRED;
        $this->expired_at = $now;
        $this->occurred_at = $now;
        $this->recorded_at = $now;
    }

    public function isEffectiveOn(CarbonImmutable $date, ?string $facilityId = null): bool
    {
        if ($this->status !== PayerContractStatus::ACTIVE) {
            return false;
        }

        if ($date->lt($this->effective_from)) {
            return false;
        }

        if ($this->effective_to !== null && $date->gt($this->effective_to)) {
            return false;
        }

        if ($this->facility_id !== null && $facilityId !== null && $this->facility_id !== $facilityId) {
            return false;
        }

        return true;
    }

    public function isDraft(): bool
    {
        return $this->status === PayerContractStatus::DRAFT;
    }

    public function isActive(): bool
    {
        return $this->status === PayerContractStatus::ACTIVE;
    }

    public function isTerminated(): bool
    {
        return $this->status === PayerContractStatus::TERMINATED;
    }

    public function isExpired(): bool
    {
        return $this->status === PayerContractStatus::EXPIRED;
    }
}
