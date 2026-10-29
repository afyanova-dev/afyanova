<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Model;

use App\Domains\Insurance\Database\Factories\PayerFactory;
use App\Domains\Insurance\Domain\ValueObject\PayerStatus;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * A payer organization — the entity financially responsible for covered
 * services (21-insurance-and-payer-domain.md §8, §9, §10; ADR-198 D-G).
 *
 * Insurance's own "who may be responsible for payment" master. `code` is
 * SET-ONCE — the aggregate exposes no method to change it. `name` / contact
 * change via {@see self::rename()}; status via {@see self::activate()} /
 * {@see self::deactivate()}. `SELF_PAY` is NOT a payer (§141 / §285) — Billing's
 * `payer_type = 'SELF_PAY'` is the "no payer" sentinel. No events — payer-master
 * change is configuration (the audit log records every change); the Procurement
 * `Supplier` master emits none either.
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
 * @property string $code
 * @property string $name
 * @property string $payer_type
 * @property string $status
 * @property string|null $status_reason
 * @property string|null $contact_name
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property string|null $created_by_user_id
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $recorded_at
 * @property int $version
 */
final class Payer extends AggregateRoot
{
    /** @use HasFactory<PayerFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = PayerStatus::ACTIVE;

    public const STATUS_INACTIVE = PayerStatus::INACTIVE;

    protected $table = 'ins_payers';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id',
        'code', 'name', 'payer_type', 'status', 'status_reason',
        'contact_name', 'contact_email', 'contact_phone',
        'created_by_user_id',
        'occurred_at', 'recorded_at',
        'version',
    ];

    protected static function newFactory(): PayerFactory
    {
        return PayerFactory::new();
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

    public static function register(
        string $id,
        string $tenantId,
        string $code,
        string $name,
        string $payerType,
        ?string $contactName,
        ?string $contactEmail,
        ?string $contactPhone,
        string $createdByUserId,
        CarbonImmutable $occurredAt,
        CarbonImmutable $recordedAt,
    ): self {
        return new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'code' => $code,
            'name' => $name,
            'payer_type' => $payerType,
            'status' => self::STATUS_ACTIVE,
            'status_reason' => null,
            'contact_name' => $contactName,
            'contact_email' => $contactEmail,
            'contact_phone' => $contactPhone,
            'created_by_user_id' => $createdByUserId,
            'occurred_at' => $occurredAt,
            'recorded_at' => $recordedAt,
        ]);
    }

    /** Change the display name and/or contact fields. `code` is SET-ONCE — never here. */
    public function rename(?string $name, ?string $contactName, ?string $contactEmail, ?string $contactPhone): void
    {
        if ($name !== null && trim($name) !== '') {
            $this->name = trim($name);
        }
        if ($contactName !== null) {
            $this->contact_name = trim($contactName) === '' ? null : trim($contactName);
        }
        if ($contactEmail !== null) {
            $this->contact_email = trim($contactEmail) === '' ? null : trim($contactEmail);
        }
        if ($contactPhone !== null) {
            $this->contact_phone = trim($contactPhone) === '' ? null : trim($contactPhone);
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
