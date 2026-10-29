<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application;

use App\Domains\Patient\Application\Command\RegisterPatient;
use App\Domains\Patient\Application\Command\RegisterPatientAtFacility;
use App\Domains\Patient\Application\Command\RegisterPatientResult;
use App\Domains\Patient\Application\Command\RegisterPatientReview;
use App\Domains\Patient\Application\Query\GetPatientSummaries;
use App\Domains\Patient\Application\Query\GetPatientSummary;
use App\Domains\Patient\Application\Query\SearchPatients;
use App\Domains\Patient\Contracts\Dto\PatientNotificationContactDto;
use App\Domains\Patient\Contracts\Dto\PatientSearchDetails;
use App\Domains\Patient\Contracts\Dto\PatientSearchResult;
use App\Domains\Patient\Contracts\Dto\PatientSummary;
use App\Domains\Patient\Contracts\Dto\PharmacyOnlyPatientRegistrationOutcome;
use App\Domains\Patient\Contracts\PatientApi;
use App\Domains\Patient\Domain\Enum\DobPrecision;
use App\Domains\Patient\Domain\Enum\Sex;
use App\Domains\Patient\Domain\PatientReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Thin port implementation (IC §5.2). `patientExists` is an unprotected
 * reference-style read — it delegates straight to the Reader (matches
 * OrganizationApiService's precedent, IC §6.2's reference-read carve-out: no
 * QueryBus ceremony for data that carries no PHI). `getPatientSummary` and
 * `registerAtFacility` cross the bus — they expose/change protected state, so
 * the bus's authorization step must never be skipped (IC §12).
 */
final class PatientApiService implements PatientApi
{
    public function __construct(
        private readonly PatientReader $reader,
        private readonly QueryBus $queryBus,
        private readonly CommandBus $commandBus,
        private readonly ScopeChecker $scope,
    ) {}

    /**
     * @param  list<string>  $patientIds
     * @return array<string, PatientSearchDetails>
     */
    public function getPatientSearchDetails(array $patientIds, string $facilityId): array
    {
        $user = Auth::user();
        if (! $user instanceof User
            || ! $this->scope->withinFacilityScope($user, $facilityId)
            || ! in_array('patient.patients.read', $this->scope->effectivePermissions($user, $facilityId), true)) {
            throw new RuntimeException('Patient search details require patient read access at the facility.');
        }

        return $this->reader->searchDetails(array_values(array_unique($patientIds)), $facilityId);
    }

    public function patientExists(string $patientId): bool
    {
        return $this->reader->exists($patientId);
    }

    public function patientIsRegisteredAtFacility(string $patientId, string $facilityId): bool
    {
        return $this->reader->isRegisteredAtFacility($patientId, $facilityId);
    }

    public function getPatientSummary(string $patientId): PatientSummary
    {
        /** @var PatientSummary */
        return $this->queryBus->dispatch(new GetPatientSummary($patientId));
    }

    /**
     * @param  list<string>  $patientIds
     * @return array<string, PatientSummary>
     */
    public function getPatientSummaries(array $patientIds): array
    {
        /** @var array<string, PatientSummary> */
        return $this->queryBus->dispatch(new GetPatientSummaries($patientIds));
    }

    public function getNotificationContact(string $patientId): ?PatientNotificationContactDto
    {
        return $this->reader->notificationContact($patientId);
    }

    public function searchPatients(
        ?string $patientNumber = null,
        ?string $nationalId = null,
        ?string $insuranceId = null,
        ?string $phone = null,
        ?string $nameFragment = null,
        ?string $dateOfBirth = null,
        int $page = 1,
        int $perPage = 25,
    ): LengthAwarePaginator {
        /** @var LengthAwarePaginator<int, PatientSearchResult> */
        return $this->queryBus->dispatch(new SearchPatients(
            patientNumber: $patientNumber,
            nationalId: $nationalId,
            insuranceId: $insuranceId,
            phone: $phone,
            nameFragment: $nameFragment,
            dateOfBirth: $dateOfBirth,
            page: $page,
            perPage: $perPage,
        ));
    }

    public function registerAtFacility(string $patientId, string $facilityId): void
    {
        $this->commandBus->dispatch(new RegisterPatientAtFacility($patientId, $facilityId));
    }

    public function registerPharmacyOnlyPatient(
        string $patientId,
        string $actorId,
        string $givenName,
        string $familyName,
        string $sex,
        ?string $primaryPhone,
    ): PharmacyOnlyPatientRegistrationOutcome {
        $result = $this->commandBus->dispatch(new RegisterPatient(
            patientId: $patientId,
            actorId: $actorId,
            givenName: $givenName,
            familyName: $familyName,
            middleName: null,
            displayName: trim($givenName.' '.$familyName),
            sex: Sex::from($sex),
            dateOfBirth: null,
            dobPrecision: DobPrecision::Unknown,
            primaryPhone: $primaryPhone,
            email: null,
            provisional: true,
            source: 'PHARMACY_EXTERNAL_PRESCRIPTION',
        ));

        if ($result instanceof RegisterPatientResult) {
            return PharmacyOnlyPatientRegistrationOutcome::created($result->patientId, $result->patientNumber);
        }

        if ($result instanceof RegisterPatientReview) {
            return PharmacyOnlyPatientRegistrationOutcome::reviewRequired(array_map(
                static fn ($candidate): array => [
                    'patientId' => $candidate->patientId,
                    'patientNumber' => $candidate->patientNumber,
                    'displayName' => $candidate->displayName,
                ],
                $result->candidates,
            ));
        }

        throw new \LogicException('Patient registration returned an unsupported outcome.');
    }
}
