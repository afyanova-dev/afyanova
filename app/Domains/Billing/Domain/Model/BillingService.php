<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\Model;

use App\Domains\Billing\Database\Factories\BillingServiceFactory;
use App\Domains\Billing\Domain\ValueObject\BillingServiceStatus;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * The billable-service catalog entry (20-billing-and-payments-domain.md §13,
 * §236, §237; ADR-188 D-A).
 *
 * Billing's own "what can be charged for" master. `code` is SET-ONCE — the
 * aggregate exposes no method to change it. `name` / `category` change via
 * {@see self::rename()}; status via {@see self::activate()} / {@see self::deactivate()}.
 * Taking a service INACTIVE does NOT touch its `bill_tariffs` rows (ADR-188 D-C).
 * No events — Bill.2 is configuration (the audit log records every change).
 *
 * @domain      Billing
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @facility    not-applicable
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property string $category
 * @property string $status
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $recorded_at
 * @property int $version
 */
final class BillingService extends AggregateRoot
{
    /** @use HasFactory<BillingServiceFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = BillingServiceStatus::ACTIVE;

    public const STATUS_INACTIVE = BillingServiceStatus::INACTIVE;

    protected $table = 'bill_billing_services';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id',
        'code', 'name', 'category', 'status',
        'occurred_at', 'recorded_at',
        'version',
    ];

    protected static function newFactory(): BillingServiceFactory
    {
        return BillingServiceFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public static function add(
        string $id,
        string $tenantId,
        string $code,
        string $name,
        string $category,
        CarbonImmutable $occurredAt,
        CarbonImmutable $recordedAt,
    ): self {
        return new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'code' => $code,
            'name' => $name,
            'category' => $category,
            'status' => self::STATUS_ACTIVE,
            'occurred_at' => $occurredAt,
            'recorded_at' => $recordedAt,
        ]);
    }

    /** Change the display name and/or category. `code` is SET-ONCE — never here. */
    public function rename(?string $name, ?string $category): void
    {
        if ($name !== null && trim($name) !== '') {
            $this->name = trim($name);
        }
        if ($category !== null) {
            $this->category = $category;
        }
    }

    public function activate(): void
    {
        $this->status = self::STATUS_ACTIVE;
    }

    public function deactivate(): void
    {
        $this->status = self::STATUS_INACTIVE;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
