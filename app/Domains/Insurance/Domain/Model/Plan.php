<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Model;

use App\Domains\Insurance\Database\Factories\PlanFactory;
use App\Domains\Insurance\Domain\ValueObject\PlanStatus;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * A payer plan — a specific coverage product offered by a payer
 * (21-insurance-and-payer-domain.md §11, §12, §160; ADR-198 D-H).
 *
 * Distinct from the payer (§11) and from a patient's membership / coverage
 * (§13 — Ins.2). `code` is SET-ONCE and unique per payer. `payer_id` is a
 * plain reference (no DB foreign key — the Billing precedent), verified in the
 * handler. `effective_from` / `effective_to` are STORED here but not enforced
 * in Ins.1 — Ins.2 `RegisterMemberCoverage` gates coverage to the plan window.
 * Benefit configuration / coverage rules / limits / co-pay / authorization
 * requirements (§12) are Ins.3. No events — configuration.
 *
 * @domain      Insurance
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @facility    not-applicable
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $payer_id
 * @property string $code
 * @property string $name
 * @property string $status
 * @property string|null $status_reason
 * @property CarbonImmutable|null $effective_from
 * @property CarbonImmutable|null $effective_to
 * @property string|null $created_by_user_id
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $recorded_at
 * @property int $version
 */
final class Plan extends AggregateRoot
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = PlanStatus::ACTIVE;

    public const STATUS_INACTIVE = PlanStatus::INACTIVE;

    protected $table = 'ins_plans';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'payer_id',
        'code', 'name', 'status', 'status_reason',
        'effective_from', 'effective_to',
        'created_by_user_id',
        'occurred_at', 'recorded_at',
        'version',
    ];

    protected static function newFactory(): PlanFactory
    {
        return PlanFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_from' => 'immutable_date',
            'effective_to' => 'immutable_date',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public static function create(
        string $id,
        string $tenantId,
        string $payerId,
        string $code,
        string $name,
        ?CarbonImmutable $effectiveFrom,
        ?CarbonImmutable $effectiveTo,
        string $createdByUserId,
        CarbonImmutable $occurredAt,
        CarbonImmutable $recordedAt,
    ): self {
        return new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'payer_id' => $payerId,
            'code' => $code,
            'name' => $name,
            'status' => self::STATUS_ACTIVE,
            'status_reason' => null,
            'effective_from' => $effectiveFrom,
            'effective_to' => $effectiveTo,
            'created_by_user_id' => $createdByUserId,
            'occurred_at' => $occurredAt,
            'recorded_at' => $recordedAt,
        ]);
    }

    /** Change the display name. `code` / `payer_id` are SET-ONCE — never here. */
    public function rename(?string $name): void
    {
        if ($name !== null && trim($name) !== '') {
            $this->name = trim($name);
        }
    }

    /**
     * Patch the effective window. A null argument leaves that bound unchanged;
     * pass the same value to clear vs set is the handler's job (Ins.1 keeps it
     * simple — null = unchanged).
     */
    public function reviseEffectivePeriod(?CarbonImmutable $from, ?CarbonImmutable $to): void
    {
        if ($from !== null) {
            $this->effective_from = $from;
        }
        if ($to !== null) {
            $this->effective_to = $to;
        }
    }

    public function activate(): void
    {
        $this->status = self::STATUS_ACTIVE;
        $this->status_reason = null;
    }

    public function deactivate(?string $reason): void
    {
        $this->status = self::STATUS_INACTIVE;
        $this->status_reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
