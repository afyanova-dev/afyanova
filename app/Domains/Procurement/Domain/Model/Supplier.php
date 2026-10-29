<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Model;

use App\Domains\Procurement\Database\Factories\SupplierFactory;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use InvalidArgumentException;

/**
 * The Supplier Master aggregate (19-procurement-domain.md §9–§16, §105, §229;
 * ADR-171 D-C / D-D).
 *
 * An HTTP-command aggregate with a governed §12 lifecycle — NOT console-authored
 * seed data. Tenant-scoped, NOT facility-scoped. `status` is open-coded
 * `STATUS_*` string constants — NOT a PHP enum, NOT `#[TypeScript]` (the Inv.1
 * re-audit lesson). NO bank / payment-execution data (§229 / ADR-136).
 *
 * @domain      Procurement
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @facility    none
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property string|null $registration_number
 * @property string|null $licence_number
 * @property string|null $licence_authority
 * @property CarbonImmutable|null $licence_expiry_date
 * @property string|null $contact_name
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property string|null $address
 * @property string|null $tax_identifier
 * @property string|null $payment_terms
 * @property list<string>|null $categories
 * @property string $status
 * @property string|null $status_reason
 * @property string $created_by_user_id
 * @property CarbonImmutable|null $occurred_at
 * @property CarbonImmutable|null $recorded_at
 * @property int $version
 */
final class Supplier extends AggregateRoot
{
    /** @use HasFactory<SupplierFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_SUSPENDED = 'SUSPENDED';

    public const STATUS_INACTIVE = 'INACTIVE';

    public const STATUS_BLACKLISTED = 'BLACKLISTED';

    private const MAX_CODE_LENGTH = 100;

    private const MAX_NAME_LENGTH = 200;

    protected $table = 'pcr_suppliers';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id',
        'code', 'name', 'registration_number',
        'licence_number', 'licence_authority', 'licence_expiry_date',
        'contact_name', 'contact_email', 'contact_phone', 'address',
        'tax_identifier', 'payment_terms', 'categories',
        'status', 'status_reason',
        'created_by_user_id', 'occurred_at', 'recorded_at',
        'version',
    ];

    protected static function newFactory(): SupplierFactory
    {
        return SupplierFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'categories' => 'array',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'licence_expiry_date' => 'immutable_date',
            'version' => 'integer',
        ];
    }

    /**
     * @param  list<string>  $categories
     */
    public static function register(
        string $id,
        string $tenantId,
        string $code,
        string $name,
        string $createdByUserId,
        ?string $registrationNumber = null,
        ?string $contactName = null,
        ?string $contactEmail = null,
        ?string $contactPhone = null,
        ?string $address = null,
        ?string $taxIdentifier = null,
        ?string $paymentTerms = null,
        array $categories = [],
        ?CarbonImmutable $occurredAt = null,
        ?string $licenceNumber = null,
        ?string $licenceAuthority = null,
        ?string $licenceExpiryDate = null,
    ): self {
        $now = CarbonImmutable::now();

        return new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'code' => self::assertCode($code),
            'name' => self::assertName($name),
            'registration_number' => self::blankToNull($registrationNumber),
            'licence_number' => self::blankToNull($licenceNumber),
            'licence_authority' => self::blankToNull($licenceAuthority),
            'licence_expiry_date' => self::blankToNull($licenceExpiryDate),
            'contact_name' => self::blankToNull($contactName),
            'contact_email' => self::blankToNull($contactEmail),
            'contact_phone' => self::blankToNull($contactPhone),
            'address' => self::blankToNull($address),
            'tax_identifier' => self::blankToNull($taxIdentifier),
            'payment_terms' => self::blankToNull($paymentTerms),
            'categories' => array_values(array_unique(array_map(
                static fn (string $c): string => strtoupper(trim($c)),
                array_filter($categories, static fn (string $c): bool => trim($c) !== ''),
            ))),
            'status' => self::STATUS_ACTIVE,
            'status_reason' => null,
            'created_by_user_id' => $createdByUserId,
            'occurred_at' => $occurredAt ?? $now,
            'recorded_at' => $now,
        ]);
    }

    /**
     * @param  list<string>|null  $categories
     */
    public function updateDetails(
        ?string $name = null,
        ?string $registrationNumber = null,
        ?string $contactName = null,
        ?string $contactEmail = null,
        ?string $contactPhone = null,
        ?string $address = null,
        ?string $taxIdentifier = null,
        ?string $paymentTerms = null,
        ?array $categories = null,
    ): void {
        if ($name !== null) {
            $this->name = self::assertName($name);
        }
        foreach ([
            'registration_number' => $registrationNumber,
            'contact_name' => $contactName,
            'contact_email' => $contactEmail,
            'contact_phone' => $contactPhone,
            'address' => $address,
            'tax_identifier' => $taxIdentifier,
            'payment_terms' => $paymentTerms,
        ] as $column => $value) {
            if ($value !== null) {
                $this->{$column} = self::blankToNull($value);
            }
        }
        if ($categories !== null) {
            $this->categories = array_values(array_unique(array_map(
                static fn (string $c): string => strtoupper(trim($c)),
                array_filter($categories, static fn (string $c): bool => trim($c) !== ''),
            )));
        }

        $this->version = (int) $this->version + 1;
    }

    /** Record a lifecycle status change (transition already validated by the lifecycle service). */
    public function changeStatus(string $status, string $reason): void
    {
        $this->status = $status;
        $this->status_reason = trim($reason) !== '' ? trim($reason) : null;
        $this->version = (int) $this->version + 1;
    }

    public function updateLicenceDetails(
        ?string $licenceNumber,
        ?string $licenceAuthority,
        ?string $licenceExpiryDate,
    ): void {
        $this->licence_number = self::blankToNull($licenceNumber);
        $this->licence_authority = self::blankToNull($licenceAuthority);
        $this->licence_expiry_date = self::blankToNull($licenceExpiryDate);
        $this->version = (int) $this->version + 1;
    }

    public function isOrderable(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    private static function assertCode(string $code): string
    {
        $trimmed = trim($code);
        if ($trimmed === '') {
            throw new InvalidArgumentException('Supplier::$code must not be blank (ADR-171 D-C).');
        }
        if (mb_strlen($trimmed) > self::MAX_CODE_LENGTH) {
            throw new InvalidArgumentException(sprintf('Supplier::$code must be at most %d characters.', self::MAX_CODE_LENGTH));
        }

        return strtoupper($trimmed);
    }

    private static function assertName(string $name): string
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            throw new InvalidArgumentException('Supplier::$name must not be blank (ADR-171 D-C).');
        }
        if (mb_strlen($trimmed) > self::MAX_NAME_LENGTH) {
            throw new InvalidArgumentException(sprintf('Supplier::$name must be at most %d characters.', self::MAX_NAME_LENGTH));
        }

        return $trimmed;
    }

    private static function blankToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
