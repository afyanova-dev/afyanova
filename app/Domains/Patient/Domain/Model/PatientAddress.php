<?php

declare(strict_types=1);

namespace App\Domains\Patient\Domain\Model;

use App\Domains\Patient\Contracts\Event\PatientAddressUpdated;
use App\Domains\Patient\Database\Factories\PatientAddressFactory;
use App\Domains\Patient\Domain\Enum\AddressStatus;
use App\Domains\Patient\Domain\Enum\AddressType;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * A patient address (10-patient-domain.md §33-§34, §152; ADR-126).
 *
 * Its OWN small aggregate (ADR-126 D-1) — same reasoning as PatientContact /
 * PatientIdentifier. Fixed §33 columns; "extensible" (§33) is served by
 * additive migrations, not a JSON blob. `patient_id` is a plain indexed uuid —
 * no database foreign key (shipped precedent).
 *
 * §34 ("changing the current address must not erase historical records") is
 * honoured without a history table: a MOVE is a NEW row with the prior one
 * deactivated; an in-place revise is a correction to that same record. Rows are
 * never deleted.
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
 * @property AddressType $type
 * @property AddressStatus $status
 * @property CarbonImmutable|null $removed_at
 */
final class PatientAddress extends AggregateRoot
{
    /** @use HasFactory<PatientAddressFactory> */
    use HasFactory;

    protected $table = 'pat_patient_addresses';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'patient_id',
        'type', 'region', 'district', 'ward', 'street_or_village', 'postal_code', 'notes',
        'status', 'recorded_by_user_id', 'removed_by_user_id', 'removed_at',
    ];

    protected static function newFactory(): PatientAddressFactory
    {
        return PatientAddressFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AddressType::class,
            'status' => AddressStatus::class,
            'removed_at' => 'immutable_datetime',
        ];
    }

    /**
     * Bring a new address into being (ADR-126 A.4c — UpdatePatientAddress
     * upsert, create branch). Always ACTIVE. The caller has already verified
     * the patient exists in the current tenant.
     */
    public static function open(
        string $id,
        string $tenantId,
        string $patientId,
        AddressType $type,
        ?string $region,
        ?string $district,
        ?string $ward,
        ?string $streetOrVillage,
        ?string $postalCode,
        ?string $notes,
        string $recordedByUserId,
    ): self {
        $address = new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'patient_id' => $patientId,
            'type' => $type,
            'region' => $region,
            'district' => $district,
            'ward' => $ward,
            'street_or_village' => $streetOrVillage,
            'postal_code' => $postalCode,
            'notes' => $notes,
            'status' => AddressStatus::Active,
            'recorded_by_user_id' => $recordedByUserId,
        ]);

        $address->recordUpdated();

        return $address;
    }

    /**
     * Revise an existing address (ADR-126 A.4c — UpdatePatientAddress upsert,
     * update branch). A content revise is a CORRECTION to this record; a move
     * to a new address is a new row via open(). `$isActive === false`
     * deactivates (row + audit trail kept — D-8 / §34); `true` (re)activates.
     */
    public function revise(
        AddressType $type,
        ?string $region,
        ?string $district,
        ?string $ward,
        ?string $streetOrVillage,
        ?string $postalCode,
        ?string $notes,
        bool $isActive,
        string $actorId,
    ): void {
        $this->type = $type;
        $this->region = $region;
        $this->district = $district;
        $this->ward = $ward;
        $this->street_or_village = $streetOrVillage;
        $this->postal_code = $postalCode;
        $this->notes = $notes;

        if ($isActive) {
            $this->status = AddressStatus::Active;
            $this->removed_by_user_id = null;
            $this->removed_at = null;
        } elseif ($this->status === AddressStatus::Active) {
            $this->status = AddressStatus::Inactive;
            $this->removed_by_user_id = $actorId;
            $this->removed_at = CarbonImmutable::now();
        }

        $this->recordUpdated();
    }

    private function recordUpdated(): void
    {
        $this->recordThat(new PatientAddressUpdated(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            patientId: (string) $this->patient_id,
            addressId: (string) $this->id,
            addressType: $this->type->value,
            isActive: $this->status === AddressStatus::Active,
            occurredAt: (string) now()->toISOString(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }
}
