<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\Model;

use App\Domains\Billing\Database\Factories\CashSessionFactory;
use App\Domains\Billing\Domain\Exception\IllegalCashSessionTransition;
use App\Domains\Billing\Domain\ValueObject\CashSessionStatus;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * A cashier's till session (20-billing-and-payments-domain.md §153–§157;
 * ADR-191 C6-A…C6-D / G-4).
 *
 * A cash session answers "how much physical cash should be in this drawer" —
 * a DIFFERENT quantity from the account ledger balance (G-4). It posts NOTHING
 * to `bill_account_ledger_entries`. `expected_cash` = `opening_float + Σ(CASH
 * payments attributed to the session)`; non-cash payments are attributed for
 * the per-method panel but EXCLUDED from the cash count. `close()` FREEZES
 * `expected_cash` / `counted_cash` / `variance` (§155 / §156 — the Bill.4
 * frozen-`net` pattern); the variance is recorded here + a §157 approval, NEVER
 * on the account ledger (C6-C). Records no event.
 *
 * @domain      Billing
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @facility    required
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $facility_id
 * @property string $cashier_user_id
 * @property string $opening_float
 * @property string $currency
 * @property string $status
 * @property string|null $expected_cash
 * @property string|null $counted_cash
 * @property string|null $variance
 * @property CarbonImmutable $opened_at
 * @property string $opened_by_user_id
 * @property CarbonImmutable|null $closed_at
 * @property string|null $closed_by_user_id
 * @property string|null $close_note
 * @property CarbonImmutable|null $reconciled_at
 * @property string|null $reconciled_by_user_id
 * @property string|null $variance_approved_by_user_id
 * @property string|null $variance_note
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $recorded_at
 * @property int $version
 */
final class CashSession extends AggregateRoot
{
    /** @use HasFactory<CashSessionFactory> */
    use HasFactory;

    public const STATUS_OPEN = CashSessionStatus::OPEN;

    public const STATUS_ACTIVE = CashSessionStatus::ACTIVE;

    public const STATUS_CLOSED = CashSessionStatus::CLOSED;

    public const STATUS_RECONCILED = CashSessionStatus::RECONCILED;

    protected $table = 'bill_cash_sessions';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id', 'cashier_user_id',
        'opening_float', 'currency', 'status',
        'expected_cash', 'counted_cash', 'variance',
        'opened_at', 'opened_by_user_id',
        'closed_at', 'closed_by_user_id', 'close_note',
        'reconciled_at', 'reconciled_by_user_id', 'variance_approved_by_user_id', 'variance_note',
        'occurred_at', 'recorded_at', 'version',
    ];

    protected static function newFactory(): CashSessionFactory
    {
        return CashSessionFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opening_float' => 'decimal:4',
            'expected_cash' => 'decimal:4',
            'counted_cash' => 'decimal:4',
            'variance' => 'decimal:4',
            'opened_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'reconciled_at' => 'immutable_datetime',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    /**
     * @param  numeric-string  $openingFloat  scale-2, >= 0
     */
    public static function open(
        string $id,
        string $tenantId,
        string $facilityId,
        string $cashierUserId,
        string $openingFloat,
        string $currency,
        string $openedByUserId,
        CarbonImmutable $occurredAt,
        CarbonImmutable $recordedAt,
    ): self {
        return new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'cashier_user_id' => $cashierUserId,
            'opening_float' => $openingFloat,
            'currency' => $currency,
            'status' => self::STATUS_OPEN,
            'opened_at' => $occurredAt,
            'opened_by_user_id' => $openedByUserId,
            'occurred_at' => $occurredAt,
            'recorded_at' => $recordedAt,
        ]);
    }

    /** OPEN → ACTIVE on the first payment attributed to the session. Idempotent. */
    public function activate(): void
    {
        if ($this->status === self::STATUS_OPEN) {
            $this->status = self::STATUS_ACTIVE;
        }
    }

    /**
     * {OPEN | ACTIVE} → CLOSED. Freezes the three cash figures (§155 / §156).
     *
     * @param  numeric-string  $expectedCash
     * @param  numeric-string  $countedCash
     * @param  numeric-string  $variance  countedCash − expectedCash (may be negative)
     */
    public function close(string $expectedCash, string $countedCash, string $variance, string $closedByUserId, ?string $note): void
    {
        if (! in_array($this->status, CashSessionStatus::LIVE, true)) {
            throw IllegalCashSessionTransition::fromStatus('CloseCashSession', (string) $this->status);
        }

        $this->status = self::STATUS_CLOSED;
        $this->expected_cash = $expectedCash;
        $this->counted_cash = $countedCash;
        $this->variance = $variance;
        $this->closed_at = CarbonImmutable::now();
        $this->closed_by_user_id = $closedByUserId;
        $this->close_note = $note;
    }

    /** CLOSED → RECONCILED (§157). Idempotent on RECONCILED. */
    public function reconcile(string $reconciledByUserId, ?string $varianceApprovedByUserId, ?string $varianceNote): void
    {
        if ($this->status === self::STATUS_RECONCILED) {
            return;
        }

        if ($this->status !== self::STATUS_CLOSED) {
            throw IllegalCashSessionTransition::fromStatus('ReconcileCashSession', (string) $this->status);
        }

        $this->status = self::STATUS_RECONCILED;
        $this->reconciled_at = CarbonImmutable::now();
        $this->reconciled_by_user_id = $reconciledByUserId;
        $this->variance_approved_by_user_id = $varianceApprovedByUserId;
        $this->variance_note = $varianceNote;
    }

    public function isLive(): bool
    {
        return in_array($this->status, CashSessionStatus::LIVE, true);
    }
}
