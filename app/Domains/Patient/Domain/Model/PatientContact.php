<?php

declare(strict_types=1);

namespace App\Domains\Patient\Domain\Model;

use App\Domains\Patient\Contracts\Event\PatientContactUpdated;
use App\Domains\Patient\Database\Factories\PatientContactFactory;
use App\Domains\Patient\Domain\Enum\ContactStatus;
use App\Domains\Patient\Domain\Enum\ContactType;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * A SECONDARY / additional patient contact point (10-patient-domain.md §31-§32,
 * §152; ADR-126).
 *
 * Its OWN small aggregate (ADR-126 D-1) — same reasoning as PatientIdentifier /
 * PatientRelationship. It is NEVER a second source of truth for
 * pat_patients.primary_phone / email (ADR-126 D-2); those stay core Patient
 * fields. `patient_id` is a plain indexed uuid — no database foreign key
 * (shipped precedent); the tenant-correct guard is the handler loading the
 * patient through the RLS-scoped PatientRepository first.
 *
 * Larastan reads the `$casts` property, not `casts()`, so the effective types
 * are pinned in `@property` tags for callers outside this class.
 *
 * @domain      Patient
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @facility    not applicable — identity-level, tenant-scoped (ADR-114 / ADR-126).
 *
 * @property ContactType $type
 * @property ContactStatus $status
 * @property CarbonImmutable|null $removed_at
 */
final class PatientContact extends AggregateRoot
{
    /** @use HasFactory<PatientContactFactory> */
    use HasFactory;

    protected $table = 'pat_patient_contacts';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'patient_id',
        'type', 'value', 'normalized_value', 'label', 'is_preferred',
        'status', 'recorded_by_user_id', 'removed_by_user_id', 'removed_at',
    ];

    protected static function newFactory(): PatientContactFactory
    {
        return PatientContactFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ContactType::class,
            'status' => ContactStatus::class,
            'is_preferred' => 'boolean',
            'removed_at' => 'immutable_datetime',
        ];
    }

    /**
     * Case-folded matching form: an email lower-cased and trimmed, a phone
     * reduced to digits, anything else trimmed. Pure — no I/O.
     */
    public static function normalize(ContactType $type, string $value): string
    {
        if ($type->isEmail()) {
            return Str::lower(trim($value));
        }

        if ($type === ContactType::Other) {
            return trim($value);
        }

        return (string) preg_replace('/\D+/', '', $value);
    }

    /**
     * Bring a new contact point into being (ADR-126 A.4b — UpdatePatientContact
     * upsert, create branch). Always ACTIVE. The caller has already verified
     * the patient exists in the current tenant.
     */
    public static function open(
        string $id,
        string $tenantId,
        string $patientId,
        ContactType $type,
        string $value,
        ?string $label,
        bool $isPreferred,
        string $recordedByUserId,
    ): self {
        $contact = new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'patient_id' => $patientId,
            'type' => $type,
            'value' => $value,
            'normalized_value' => self::normalize($type, $value),
            'label' => $label,
            'is_preferred' => $isPreferred,
            'status' => ContactStatus::Active,
            'recorded_by_user_id' => $recordedByUserId,
        ]);

        $contact->recordUpdated();

        return $contact;
    }

    /**
     * Revise an existing contact point (ADR-126 A.4b — UpdatePatientContact
     * upsert, update branch). `$isActive === false` deactivates it (row + audit
     * trail kept — D-8); `true` (re)activates.
     */
    public function revise(
        ContactType $type,
        string $value,
        ?string $label,
        bool $isPreferred,
        bool $isActive,
        string $actorId,
    ): void {
        $this->type = $type;
        $this->value = $value;
        $this->normalized_value = self::normalize($type, $value);
        $this->label = $label;
        $this->is_preferred = $isPreferred;

        if ($isActive) {
            $this->status = ContactStatus::Active;
            $this->removed_by_user_id = null;
            $this->removed_at = null;
        } elseif ($this->status === ContactStatus::Active) {
            $this->status = ContactStatus::Inactive;
            $this->removed_by_user_id = $actorId;
            $this->removed_at = CarbonImmutable::now();
        }

        $this->recordUpdated();
    }

    private function recordUpdated(): void
    {
        $this->recordThat(new PatientContactUpdated(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            patientId: (string) $this->patient_id,
            contactId: (string) $this->id,
            contactType: $this->type->value,
            isActive: $this->status === ContactStatus::Active,
            occurredAt: (string) now()->toISOString(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }
}
