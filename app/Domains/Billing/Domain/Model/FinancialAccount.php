<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\Model;

use App\Domains\Billing\Contracts\Event\FinancialAccountClosed;
use App\Domains\Billing\Contracts\Event\FinancialAccountOpened;
use App\Domains\Billing\Database\Factories\FinancialAccountFactory;
use App\Domains\Billing\Domain\Exception\IllegalFinancialAccountTransition;
use App\Domains\Billing\Domain\ValueObject\FinancialAccountStatus;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * The Financial Account aggregate — the root of the Billing & Payments domain
 * (20-billing-and-payments-domain.md §7, §8, §9, §144–§149, §300, §301;
 * ADR-187 D-B).
 *
 * ONE account per patient per facility (HD-1). Bill.1 delivers the record +
 * open / close only: {@see self::open()} → OPEN → {@see self::close()} → CLOSED.
 * The ACTIVE / SETTLED transitions and the charge / invoice / payment write
 * paths arrive with Bill.2+. `status` is an open-coded string constant (the
 * `PharmacyOrder` precedent — NOT a PHP enum, NOT #[TypeScript]).
 *
 * The account is a CONTAINER, not a gate — whether a service may proceed
 * financially is Financial Access's decision (ADR-051 / doc 08), Bill.6+. The
 * balance is derived from `bill_account_ledger_entries`, never held here (§84).
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
 * @property string $patient_id
 * @property string $account_number
 * @property string $status
 * @property string $currency
 * @property CarbonImmutable $opened_at
 * @property string $opened_by_user_id
 * @property CarbonImmutable|null $closed_at
 * @property string|null $closed_by_user_id
 * @property string|null $close_reason_code
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $recorded_at
 * @property int $version
 */
final class FinancialAccount extends AggregateRoot
{
    /** @use HasFactory<FinancialAccountFactory> */
    use HasFactory;

    public const STATUS_OPEN = FinancialAccountStatus::OPEN;

    public const STATUS_ACTIVE = FinancialAccountStatus::ACTIVE;

    public const STATUS_SETTLED = FinancialAccountStatus::SETTLED;

    public const STATUS_CLOSED = FinancialAccountStatus::CLOSED;

    protected $table = 'bill_financial_accounts';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id',
        'patient_id', 'account_number',
        'status', 'currency',
        'opened_at', 'opened_by_user_id',
        'closed_at', 'closed_by_user_id', 'close_reason_code',
        'occurred_at', 'recorded_at',
        'version',
    ];

    protected static function newFactory(): FinancialAccountFactory
    {
        return FinancialAccountFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opened_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public static function open(
        string $id,
        string $tenantId,
        string $facilityId,
        string $patientId,
        string $accountNumber,
        string $currency,
        string $openedByUserId,
        CarbonImmutable $occurredAt,
        CarbonImmutable $recordedAt,
    ): self {
        $account = new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'patient_id' => $patientId,
            'account_number' => $accountNumber,
            'status' => self::STATUS_OPEN,
            'currency' => $currency,
            'opened_at' => $occurredAt,
            'opened_by_user_id' => $openedByUserId,
            'occurred_at' => $occurredAt,
            'recorded_at' => $recordedAt,
        ]);

        $account->recordThat(new FinancialAccountOpened(
            eventId: (string) Str::uuid7(),
            tenantId: $tenantId,
            facilityId: $facilityId,
            patientId: $patientId,
            financialAccountId: $id,
            accountNumber: $accountNumber,
            currency: $currency,
            openedByUserId: $openedByUserId,
            occurredAt: $occurredAt->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));

        return $account;
    }

    /**
     * {OPEN | ACTIVE | SETTLED} → ACTIVE (ADR-189 — its first caller: the first
     * posted charge / a fresh charge after settlement). Idempotent. A CLOSED
     * account cannot receive a charge — `IllegalFinancialAccountTransition`
     * (unreachable in Bill.3: `openAccountForPatientAtFacilityForUpdate` returns
     * only non-closed accounts, so a raced CLOSE → the handler lazy-opens a
     * fresh one).
     */
    public function activate(): void
    {
        if ($this->status === self::STATUS_CLOSED) {
            throw IllegalFinancialAccountTransition::fromStatus('activate', self::STATUS_CLOSED);
        }

        $this->status = self::STATUS_ACTIVE;
    }

    /**
     * {OPEN | ACTIVE | SETTLED} → CLOSED (terminal). Coded reason; history is
     * preserved (§301). The zero-outstanding-balance guard is enforced in the
     * handler (it needs the ledger reader), not here.
     */
    public function close(string $closedByUserId, string $reasonCode, CarbonImmutable $occurredAt): void
    {
        $this->status = self::STATUS_CLOSED;
        $this->closed_at = $occurredAt;
        $this->closed_by_user_id = $closedByUserId;
        $this->close_reason_code = $reasonCode;

        $this->recordThat(new FinancialAccountClosed(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            financialAccountId: (string) $this->id,
            accountNumber: (string) $this->account_number,
            closeReasonCode: $reasonCode,
            closedByUserId: $closedByUserId,
            occurredAt: $occurredAt->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }
}
