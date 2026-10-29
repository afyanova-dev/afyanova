<?php

declare(strict_types=1);

namespace App\Domains\Patient\Domain\Model;

use App\Domains\Patient\Contracts\Event\PatientRegisteredAtFacility;
use App\Domains\Patient\Database\Factories\PatientRegistrationFactory;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * A patient's participation at one facility (10-patient-domain.md §109-111;
 * ADR-114). A SEPARATE aggregate from Patient — independent lifecycle (a
 * patient may be registered at a facility years after the Patient row was
 * created), own transaction boundary (IC §4.1). Never loaded as part of the
 * Patient aggregate.
 *
 * @domain      Patient
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @facility    required — this is the one pat_ table that IS facility-scoped
 *              (ADR-114): tenant_id + facility_id, both NOT NULL.
 */
final class PatientRegistration extends AggregateRoot
{
    /** @use HasFactory<PatientRegistrationFactory> */
    use HasFactory;

    protected $table = 'pat_patient_registrations';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'facility_id', 'patient_id', 'status', 'first_registered_at',
    ];

    protected static function newFactory(): PatientRegistrationFactory
    {
        return PatientRegistrationFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_registered_at' => 'immutable_datetime',
        ];
    }

    /**
     * Record a patient's participation at a facility (ADR-114). The caller
     * (PatientApi::registerAtFacility, via RegisterPatientAtFacilityHandler)
     * has already verified the patient exists in the current tenant.
     */
    public static function register(string $tenantId, string $facilityId, string $patientId): self
    {
        $registration = new self([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'patient_id' => $patientId,
            'status' => 'ACTIVE',
            'first_registered_at' => now(),
        ]);

        $registration->recordThat(new PatientRegisteredAtFacility(
            eventId: (string) Str::uuid7(),
            tenantId: $tenantId,
            patientId: $patientId,
            facilityId: $facilityId,
            occurredAt: (string) now()->toISOString(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));

        return $registration;
    }
}
