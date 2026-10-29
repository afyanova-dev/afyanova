<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\Model;

use App\Domains\HR\Contracts\Event\RegistrationAdded;
use App\Domains\HR\Contracts\Event\RegistrationStatusChanged;
use App\Domains\HR\Contracts\Event\RegistrationVerified;
use App\Domains\HR\Domain\ValueObject\RegistrationStatus;
use App\Domains\HR\Domain\ValueObject\VerificationSource;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Professional registration aggregate root (25-hr-and-workforce-domain.md §3, §6, §7, §8; ADR-206).
 *
 * One-to-many per staff. Status is a reflection/cache of external licensing authority (Invariant 7.8, 7.14).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $staff_id
 * @property string $council
 * @property string $category
 * @property string $licence_number
 * @property CarbonImmutable $issued_on
 * @property CarbonImmutable $expires_on
 * @property CarbonImmutable|null $verified_at
 * @property VerificationSource $verification_source
 * @property RegistrationStatus $status
 * @property string|null $notes
 * @property int $version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class ProfessionalRegistration extends AggregateRoot
{
    protected $table = 'hr_professional_registrations';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'staff_id',
        'council',
        'category',
        'licence_number',
        'issued_on',
        'expires_on',
        'verified_at',
        'verification_source',
        'status',
        'notes',
        'version',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'issued_on' => 'immutable_date',
        'expires_on' => 'immutable_date',
        'verified_at' => 'immutable_datetime',
        'verification_source' => VerificationSource::class,
        'status' => RegistrationStatus::class,
        'version' => 'integer',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    public static function create(
        string $tenantId,
        string $staffId,
        string $council,
        string $category,
        string $licenceNumber,
        CarbonImmutable $issuedOn,
        CarbonImmutable $expiresOn,
        ?string $registrationId = null,
        ?string $notes = null,
    ): self {
        $id = $registrationId ?? (string) Str::uuid();

        $registration = new self([
            'tenant_id' => $tenantId,
            'staff_id' => $staffId,
            'council' => trim($council),
            'category' => trim($category),
            'licence_number' => trim($licenceNumber),
            'issued_on' => $issuedOn,
            'expires_on' => $expiresOn,
            'verified_at' => null,
            'verification_source' => VerificationSource::MANUAL,
            'status' => RegistrationStatus::ACTIVE,
            'notes' => $notes,
            'version' => 0,
        ]);

        $registration->id = $id;

        $registration->recordThat(new RegistrationAdded(
            eventId: (string) Str::uuid(),
            tenantId: $tenantId,
            registrationId: $id,
            staffId: $staffId,
            council: trim($council),
            category: trim($category),
            licenceNumber: trim($licenceNumber),
            issuedOn: $issuedOn->toDateString(),
            expiresOn: $expiresOn->toDateString(),
            status: RegistrationStatus::ACTIVE->value,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
        ));

        return $registration;
    }

    public function updateStatus(RegistrationStatus $newStatus, ?string $reason = null): void
    {
        if ($this->status === $newStatus) {
            return;
        }

        $previous = $this->status;
        $this->status = $newStatus;
        $this->version++;

        $this->recordThat(new RegistrationStatusChanged(
            eventId: (string) Str::uuid(),
            tenantId: $this->tenant_id,
            registrationId: $this->id,
            staffId: $this->staff_id,
            council: $this->council,
            licenceNumber: $this->licence_number,
            previousStatus: $previous->value,
            newStatus: $newStatus->value,
            reason: $reason,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
        ));
    }

    public function recordVerification(
        CarbonImmutable $verifiedAt,
        VerificationSource $source,
        ?string $notes = null,
    ): void {
        $this->verified_at = $verifiedAt;
        $this->verification_source = $source;
        if ($notes !== null) {
            $this->notes = $notes;
        }
        $this->version++;

        $this->recordThat(new RegistrationVerified(
            eventId: (string) Str::uuid(),
            tenantId: $this->tenant_id,
            registrationId: $this->id,
            staffId: $this->staff_id,
            council: $this->council,
            licenceNumber: $this->licence_number,
            verifiedAt: $verifiedAt->toIso8601String(),
            verificationSource: $source->value,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
        ));
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'staff_id');
    }
}
