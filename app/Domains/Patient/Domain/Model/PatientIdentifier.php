<?php

declare(strict_types=1);

namespace App\Domains\Patient\Domain\Model;

use App\Domains\Patient\Contracts\Event\PatientIdentifierAdded;
use App\Domains\Patient\Contracts\Event\PatientIdentifierVerified;
use App\Domains\Patient\Database\Factories\PatientIdentifierFactory;
use App\Domains\Patient\Domain\Enum\IdentifierType;
use App\Domains\Patient\Domain\Enum\IdentifierVerificationStatus;
use App\Domains\Patient\Domain\Exception\IdentifierNotPendingVerification;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * One external / official identifier held against a patient identity
 * (10-patient-domain.md §39-43, §114; ADR-117, ADR-122).
 *
 * A SEPARATE aggregate from Patient, on the same reasoning ADR-114 uses for
 * PatientRegistration: an identifier is added and verified long after the
 * Patient row exists, on its own transaction boundary (IC §4.1), and is never
 * loaded as part of the Patient aggregate. `patient_id` is a plain indexed
 * uuid — no database foreign key, matching the shipped precedent (see the
 * pat_patient_registrations migration); the tenant-correct existence guard is
 * the handler loading the patient through the RLS-scoped PatientRepository.
 *
 * Larastan reads the `$casts` property, not the `casts()` method, so the
 * effective attribute types are pinned in `@property` tags for callers
 * outside this class.
 *
 * @domain      Patient
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @facility    not applicable — identifiers are identity-level, tenant-scoped
 *              like pat_patients (ADR-114).
 *
 * @property IdentifierType $identifier_type
 * @property IdentifierVerificationStatus $verification_status
 * @property string $value
 * @property string $normalized_value
 * @property CarbonImmutable|null $verified_at
 * @property CarbonImmutable|null $effective_from
 * @property CarbonImmutable|null $effective_to
 */
final class PatientIdentifier extends AggregateRoot
{
    /** @use HasFactory<PatientIdentifierFactory> */
    use HasFactory;

    protected $table = 'pat_patient_identifiers';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'patient_id',
        'identifier_type', 'value', 'normalized_value', 'issuing_authority',
        'verification_status', 'verified_at', 'verified_by_user_id', 'verification_source',
        'effective_from', 'effective_to',
    ];

    protected static function newFactory(): PatientIdentifierFactory
    {
        return PatientIdentifierFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'identifier_type' => IdentifierType::class,
            'verification_status' => IdentifierVerificationStatus::class,
            'verified_at' => 'immutable_datetime',
            'effective_from' => 'immutable_date',
            'effective_to' => 'immutable_date',
        ];
    }

    /**
     * The value used for uniqueness / matching: case-folded, stripped of every
     * non-alphanumeric character (spaces, hyphens, slashes). Pure — no I/O.
     */
    public static function normalize(string $value): string
    {
        return Str::upper((string) preg_replace('/[^A-Za-z0-9]/', '', $value));
    }

    /**
     * Record a new identifier against a patient (10-patient-domain.md §39;
     * ADR-117). Always created UNVERIFIED — entered data is not evidence
     * (§42), and only VERIFIED rows are uniqueness-constrained (ADR-117), so
     * an add never collides. The caller (AddPatientIdentifierHandler) has
     * already verified the patient exists in the current tenant.
     */
    public static function add(
        string $id,
        string $tenantId,
        string $patientId,
        IdentifierType $type,
        string $value,
        ?string $issuingAuthority,
        ?string $effectiveFrom,
        ?string $effectiveTo,
    ): self {
        $identifier = new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'patient_id' => $patientId,
            'identifier_type' => $type,
            'value' => $value,
            'normalized_value' => self::normalize($value),
            'issuing_authority' => $issuingAuthority,
            'verification_status' => IdentifierVerificationStatus::Unverified,
            'effective_from' => $effectiveFrom,
            'effective_to' => $effectiveTo,
        ]);

        $identifier->recordThat(new PatientIdentifierAdded(
            eventId: (string) Str::uuid7(),
            tenantId: $tenantId,
            patientId: $patientId,
            identifierId: $id,
            identifierType: $type->value,
            occurredAt: (string) now()->toISOString(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));

        return $identifier;
    }

    /**
     * Promote UNVERIFIED -> VERIFIED (10-patient-domain.md §42; ADR-117). The
     * partial unique index enforces at-most-one VERIFIED row per
     * (tenant, type, normalized value); the handler translates a violation
     * into DuplicateVerifiedIdentifier.
     */
    public function verify(string $verifiedByUserId, ?string $source): void
    {
        if ($this->verification_status !== IdentifierVerificationStatus::Unverified) {
            throw IdentifierNotPendingVerification::inState($this->verification_status);
        }

        $this->verification_status = IdentifierVerificationStatus::Verified;
        $this->verified_at = CarbonImmutable::now();
        $this->verified_by_user_id = $verifiedByUserId;
        $this->verification_source = $source;

        $this->recordThat(new PatientIdentifierVerified(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            patientId: (string) $this->patient_id,
            identifierId: (string) $this->id,
            identifierType: $this->identifier_type->value,
            occurredAt: (string) now()->toISOString(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }
}
