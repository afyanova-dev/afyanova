<?php

declare(strict_types=1);

namespace App\Domains\Patient\Infrastructure\Reader;

use App\Domains\Patient\Contracts\Dto\PatientGlance;
use App\Domains\Patient\Contracts\Dto\PatientGlanceIdentifier;
use App\Domains\Patient\Contracts\Dto\PatientNotificationContactDto;
use App\Domains\Patient\Contracts\Dto\PatientProfileData;
use App\Domains\Patient\Contracts\Dto\PatientSearchDetails;
use App\Domains\Patient\Contracts\Dto\PatientSearchResult;
use App\Domains\Patient\Contracts\Dto\PatientSummary;
use App\Domains\Patient\Domain\Enum\AddressStatus;
use App\Domains\Patient\Domain\Enum\IdentifierVerificationStatus;
use App\Domains\Patient\Domain\Enum\MergeHistoryStatus;
use App\Domains\Patient\Domain\Enum\PatientStatus;
use App\Domains\Patient\Domain\Enum\RelationshipStatus;
use App\Domains\Patient\Domain\Model\Patient;
use App\Domains\Patient\Domain\Model\PatientAddress;
use App\Domains\Patient\Domain\Model\PatientIdentifier;
use App\Domains\Patient\Domain\Model\PatientIdentityHistory;
use App\Domains\Patient\Domain\Model\PatientRegistration;
use App\Domains\Patient\Domain\Model\PatientRelationship;
use App\Domains\Patient\Domain\PatientReader;
use App\Shared\Domain\NotFound;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator as ConcreteLengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class EloquentPatientReader implements PatientReader
{
    /**
     * @param  list<string>  $patientIds
     * @return array<string, PatientSearchDetails> Keyed by patient id
     */
    public function searchDetails(array $patientIds, string $facilityId): array
    {
        if ($patientIds === []) {
            return [];
        }

        $patients = DB::table('pat_patients')
            ->leftJoin('pat_patient_registrations as registration', function ($join) use ($facilityId): void {
                $join->on('registration.patient_id', '=', 'pat_patients.id')
                    ->where('registration.facility_id', $facilityId);
            })
            ->whereIn('pat_patients.id', $patientIds)
            ->get([
                'pat_patients.id',
                'pat_patients.date_of_birth',
                'pat_patients.dob_precision',
                'pat_patients.photo_document_id',
                'registration.patient_id as registered_patient_id',
            ]);

        $details = [];
        foreach ($patients as $patient) {
            $dateOfBirth = $patient->date_of_birth;
            $details[(string) $patient->id] = new PatientSearchDetails(
                patientId: (string) $patient->id,
                dateOfBirth: $dateOfBirth instanceof \DateTimeInterface
                    ? $dateOfBirth->format('Y-m-d')
                    : ($dateOfBirth === null ? null : (string) $dateOfBirth),
                dobPrecision: $patient->dob_precision === null ? null : (string) $patient->dob_precision,
                hasPhoto: $patient->photo_document_id !== null,
                registeredAtFacility: $patient->registered_patient_id !== null,
            );
        }

        return $details;
    }

    public function exists(string $patientId): bool
    {
        return Patient::query()->whereKey($patientId)->exists();
    }

    public function isRegisteredAtFacility(string $patientId, string $facilityId): bool
    {
        return PatientRegistration::query()
            ->where('patient_id', $patientId)
            ->where('facility_id', $facilityId)
            ->exists();
    }

    public function summary(string $patientId): PatientSummary
    {
        $patient = Patient::query()->whereKey($patientId)->first()
            ?? throw NotFound::aggregate(Patient::class, $patientId);

        return $this->toSummary($patient);
    }

    /**
     * @param  list<string>  $patientIds
     * @return array<string, PatientSummary> Keyed by patientId
     */
    public function summaries(array $patientIds): array
    {
        if ($patientIds === []) {
            return [];
        }

        /** @var Collection<int, Patient> $patients */
        $patients = Patient::query()
            ->whereIn('id', $patientIds)
            ->get();

        $result = [];
        foreach ($patients as $patient) {
            $id = (string) $patient->id;
            $result[$id] = $this->toSummary($patient);
        }

        return $result;
    }

    private function toSummary(Patient $patient): PatientSummary
    {
        return new PatientSummary(
            patientId: (string) $patient->id,
            patientNumber: $patient->patient_number,
            displayName: $patient->display_name,
            dateOfBirth: $patient->date_of_birth?->toDateString(),
            sex: $patient->sex->value,
            status: $patient->status->value,
            isProvisional: $patient->is_provisional,
        );
    }

    public function notificationContact(string $patientId): ?PatientNotificationContactDto
    {
        /** @var Patient|null $patient */
        $patient = Patient::query()->whereKey($patientId)->first();
        if ($patient === null) {
            return null;
        }

        return new PatientNotificationContactDto(
            patientId: (string) $patient->id,
            phone: $patient->primary_phone,
            email: $patient->email,
            hasCommunicationConsent: $patient->status !== PatientStatus::Deceased,
            preferredLanguage: 'sw',
        );
    }

    public function glance(string $patientId, string $facilityId): PatientGlance
    {
        $patient = Patient::query()->whereKey($patientId)->first()
            ?? throw NotFound::aggregate(Patient::class, $patientId);

        /** @var list<PatientGlanceIdentifier> $identifiers */
        $identifiers = PatientIdentifier::query()
            ->where('patient_id', $patientId)
            ->where('verification_status', IdentifierVerificationStatus::Verified)
            ->orderBy('identifier_type')
            ->get()
            ->map(static fn (PatientIdentifier $id): PatientGlanceIdentifier => new PatientGlanceIdentifier(
                id: (string) $id->id,
                type: $id->identifier_type->value,
                value: (string) $id->value,
                isVerified: $id->verification_status === IdentifierVerificationStatus::Verified,
            ))
            ->values()
            ->all();

        $hasAccessibleFacilityRegistration = PatientRegistration::query()
            ->where('patient_id', $patientId)
            ->where('facility_id', $facilityId)
            ->exists();

        $facilityRegistrationCount = PatientRegistration::query()
            ->where('patient_id', $patientId)
            ->count();

        $survivingPatientId = PatientIdentityHistory::query()
            ->where('non_surviving_patient_id', $patientId)
            ->where('status', MergeHistoryStatus::Active)
            ->orderByDesc('merged_at')
            ->value('surviving_patient_id');

        return new PatientGlance(
            patientId: (string) $patient->id,
            patientNumber: $patient->patient_number,
            displayName: $patient->display_name,
            givenName: $patient->given_name,
            familyName: $patient->family_name,
            middleName: $patient->middle_name,
            sex: $patient->sex->value,
            status: $patient->status->value,
            isProvisional: $patient->is_provisional,
            dateOfBirth: $patient->date_of_birth?->toDateString(),
            dobPrecision: $patient->dob_precision->value,
            primaryPhone: $patient->primary_phone,
            email: $patient->email,
            registrationSource: $patient->registration_source,
            registeredAt: $patient->created_at?->toIso8601String(),
            hasAccessibleFacilityRegistration: $hasAccessibleFacilityRegistration,
            facilityRegistrationCount: $facilityRegistrationCount,
            survivingPatientId: $survivingPatientId === null ? null : (string) $survivingPatientId,
            identifiers: $identifiers,
        );
    }

    public function profile(string $patientId, string $facilityId): PatientProfileData
    {
        $patient = Patient::query()->whereKey($patientId)->first()
            ?? throw NotFound::aggregate(Patient::class, $patientId);

        /** @var list<PatientGlanceIdentifier> $identifiers */
        $identifiers = PatientIdentifier::query()
            ->where('patient_id', $patientId)
            ->where('verification_status', IdentifierVerificationStatus::Verified)
            ->orderBy('identifier_type')
            ->get()
            ->map(static fn (PatientIdentifier $id): PatientGlanceIdentifier => new PatientGlanceIdentifier(
                id: (string) $id->id,
                type: $id->identifier_type->value,
                value: (string) $id->value,
                isVerified: $id->verification_status === IdentifierVerificationStatus::Verified,
            ))
            ->values()
            ->all();

        $hasAccessibleFacilityRegistration = PatientRegistration::query()
            ->where('patient_id', $patientId)
            ->where('facility_id', $facilityId)
            ->exists();

        $facilityRegistrationCount = PatientRegistration::query()
            ->where('patient_id', $patientId)
            ->count();

        $survivingPatientId = PatientIdentityHistory::query()
            ->where('non_surviving_patient_id', $patientId)
            ->where('status', MergeHistoryStatus::Active)
            ->orderByDesc('merged_at')
            ->value('surviving_patient_id');

        $addresses = PatientAddress::query()
            ->where('patient_id', $patientId)
            ->where('status', AddressStatus::Active)
            ->orderByDesc('created_at')
            ->get()
            ->map(static fn (PatientAddress $addr): array => [
                'id' => (string) $addr->id,
                'type' => $addr->type->value,
                'region' => $addr->region,
                'district' => $addr->district,
                'ward' => $addr->ward,
                'streetOrVillage' => $addr->street_or_village,
                'postalCode' => $addr->postal_code,
            ])
            ->values()
            ->all();

        $relationships = PatientRelationship::query()
            ->where('patient_id', $patientId)
            ->where('status', RelationshipStatus::Active)
            ->orderByDesc('created_at')
            ->get()
            ->map(static fn (PatientRelationship $rel): array => [
                'id' => (string) $rel->id,
                'role' => $rel->role->value,
                'name' => (string) $rel->name,
                'phone' => $rel->phone,
                'label' => $rel->label,
            ])
            ->values()
            ->all();

        $auditRows = DB::table('shared_audit_log')
            ->where('target_id', $patientId)
            ->where('action', 'patient.updated')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $auditHistory = $auditRows->map(static function ($row): array {
            $meta = is_string($row->metadata) ? (json_decode($row->metadata, true) ?? []) : (array) $row->metadata;
            $changedFields = isset($meta['changed_fields']) && is_array($meta['changed_fields']) ? $meta['changed_fields'] : [];

            return [
                'action' => (string) $row->action,
                'actorName' => $row->actor_id ? 'Staff' : null,
                'reason' => $row->reason,
                'changedFields' => array_values($changedFields),
                'changedAt' => (string) $row->created_at,
            ];
        })->values()->all();

        return new PatientProfileData(
            patientId: (string) $patient->id,
            patientNumber: $patient->patient_number,
            displayName: $patient->display_name,
            givenName: $patient->given_name,
            familyName: $patient->family_name,
            middleName: $patient->middle_name,
            sex: $patient->sex->value,
            status: $patient->status->value,
            isProvisional: $patient->is_provisional,
            dateOfBirth: $patient->date_of_birth?->toDateString(),
            dobPrecision: $patient->dob_precision->value,
            primaryPhone: $patient->primary_phone,
            email: $patient->email,
            registrationSource: $patient->registration_source,
            registeredAt: $patient->created_at?->toIso8601String(),
            hasAccessibleFacilityRegistration: $hasAccessibleFacilityRegistration,
            facilityRegistrationCount: $facilityRegistrationCount,
            survivingPatientId: $survivingPatientId === null ? null : (string) $survivingPatientId,
            identifiers: $identifiers,
            addresses: $addresses,
            relationships: $relationships,
            auditHistory: $auditHistory,
            photoDocumentId: $patient->photo_document_id !== null ? (string) $patient->photo_document_id : null,
        );
    }

    /**
     * @return LengthAwarePaginator<int, PatientSearchResult>
     */
    public function recent(string $facilityId, int $page = 1, int $perPage = 25): LengthAwarePaginator
    {
        /** @var ConcreteLengthAwarePaginator<int, Patient> $paginator */
        $paginator = Patient::query()
            ->select('pat_patients.*')
            ->addSelect(['has_accessible_facility_registration' => fn ($sub) => $sub
                ->selectRaw('1')
                ->from('pat_patient_registrations as ppr')
                ->whereColumn('ppr.patient_id', 'pat_patients.id')
                ->where('ppr.facility_id', $facilityId)
                ->limit(1)])
            ->whereExists(static fn ($sub) => $sub
                ->selectRaw('1')
                ->from('pat_patient_registrations as active_facility_registration')
                ->whereColumn('active_facility_registration.patient_id', 'pat_patients.id')
                ->where('active_facility_registration.facility_id', $facilityId))
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'page', $page);

        return $paginator->through(static fn (Patient $p): PatientSearchResult => new PatientSearchResult(
            patientId: (string) $p->id,
            patientNumber: (string) $p->patient_number,
            displayName: (string) $p->display_name,
            sex: $p->sex->value,
            status: $p->status->value,
            isProvisional: (bool) $p->is_provisional,
            hasAccessibleFacilityRegistration: $p->getAttribute('has_accessible_facility_registration') !== null,
            survivingPatientId: null,
        ));
    }
}
