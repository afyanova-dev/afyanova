<?php

declare(strict_types=1);

namespace App\Domains\Patient\Presentation\Http\Controller;

use App\Domains\Encounter\Contracts\EncounterApi;
use App\Domains\Patient\Application\Query\GetRecentPatients;
use App\Domains\Patient\Application\Query\SearchPatients;
use App\Domains\Patient\Contracts\Dto\PatientSearchResult;
use App\Domains\Patient\Contracts\PatientApi;
use App\Domains\Patient\Domain\Exception\SearchCriteriaTooBroad;
use App\Domains\Patient\Presentation\Http\PatientSearchQueryResolver;
use App\Models\User;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PatientSearchApiController
{
    public function __invoke(
        Request $request,
        TenantContext $context,
        QueryBus $queryBus,
        EncounterApi $encounterApi,
        PatientApi $patientApi,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($user->can('patient.patients.read'), 403, 'Unauthorized.');

        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');

        $page = max(1, (int) $request->input('page', 1));
        $perPage = max(1, min(SearchPatients::MAX_PER_PAGE, (int) $request->input('per_page', 15)));

        try {
            $searchQuery = PatientSearchQueryResolver::resolve($request, $page, $perPage);
        } catch (SearchCriteriaTooBroad $e) {
            return response()->json([
                'patients' => [],
                'total' => 0,
                'error' => $e->getMessage(),
            ]);
        }

        if ($searchQuery === null) {
            /** @var LengthAwarePaginator<int, PatientSearchResult> $paginator */
            $paginator = $queryBus->dispatch(new GetRecentPatients(
                facilityId: $facilityId,
                page: $page,
                perPage: $perPage,
            ));
        } else {
            /** @var LengthAwarePaginator<int, PatientSearchResult> $paginator */
            $paginator = $queryBus->dispatch($searchQuery);
        }

        $canViewEncounters = $user->can('encounter.encounters.read');
        $items = $paginator->items();

        $patientIds = array_values(array_map(static fn (PatientSearchResult $r): string => $r->patientId, $items));
        $searchDetails = $patientApi->getPatientSearchDetails($patientIds, $facilityId);

        $patients = array_map(function (PatientSearchResult $r) use ($canViewEncounters, $encounterApi, $facilityId, $searchDetails): array {
            $activeEncounter = null;
            if ($canViewEncounters) {
                try {
                    $summary = $encounterApi->findActiveEncounterForPatient($facilityId, $r->patientId);
                    if ($summary !== null) {
                        $activeEncounter = [
                            'encounterId' => $summary->encounterId,
                            'encounterNumber' => $summary->encounterNumber,
                            'encounterType' => $summary->encounterType,
                            'status' => $summary->status,
                            'arrivedAt' => $summary->arrivedAt,
                        ];
                    }
                } catch (\Throwable) {
                    $activeEncounter = null;
                }
            }

            $details = $searchDetails[$r->patientId] ?? null;
            $rawDob = $details?->dateOfBirth;

            [$formattedDob, $ageDisplay] = self::dobDisplay($rawDob);

            return [
                'patientId' => $r->patientId,
                'patientNumber' => $r->patientNumber,
                'displayName' => $r->displayName,
                'sex' => $r->sex,
                'status' => $r->status,
                'isProvisional' => $r->isProvisional,
                'hasAccessibleFacilityRegistration' => $r->hasAccessibleFacilityRegistration,
                'registeredAtActiveFacility' => $details->registeredAtFacility ?? false,
                'survivingPatientId' => $r->survivingPatientId,
                'dateOfBirth' => $rawDob,
                'dobPrecision' => $details?->dobPrecision,
                'formattedDob' => $formattedDob,
                'age' => $ageDisplay,
                'photoUrl' => $details?->hasPhoto
                    ? route('patient.photo', ['patientId' => $r->patientId])
                    : null,
                'activeEncounter' => $activeEncounter,
            ];
        }, $items);

        return response()->json([
            'patients' => $patients,
            'total' => $paginator->total(),
            'currentPage' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
        ]);
    }

    /**
     * @return array{0: string|null, 1: string|null} [formattedDob, ageDisplay]
     */
    private static function dobDisplay(?string $rawDob): array
    {
        $rawDob = $rawDob === null ? null : trim($rawDob);
        if ($rawDob === null || $rawDob === '' || preg_match('/^0+-0+-0+/', $rawDob) === 1) {
            return [null, null];
        }

        $parsed = self::parseDob($rawDob);
        if ($parsed === null) {
            return [$rawDob, null];
        }

        $formattedDob = $parsed->format('d-M-Y');
        $diff = $parsed->diff(CarbonImmutable::now());

        if ($diff->y >= 2) {
            $ageDisplay = $diff->m > 0 ? "{$diff->y} yrs, {$diff->m} mths" : "{$diff->y} yrs";
        } elseif ($diff->y === 1) {
            $ageDisplay = $diff->m > 0 ? "1 yr, {$diff->m} mths" : '1 yr';
        } elseif ($diff->m > 0) {
            $ageDisplay = $diff->d > 0 ? "{$diff->m} mths, {$diff->d} days" : "{$diff->m} mths";
        } else {
            $ageDisplay = "{$diff->d} days";
        }

        return [$formattedDob, $ageDisplay];
    }

    private static function parseDob(string $rawDob): ?CarbonImmutable
    {
        foreach (['Y-m-d', 'Y-m-d H:i:s', 'Y-m-d H:i:s.u', 'd/m/Y', 'd-m-Y', 'm/d/Y', 'Y/m/d', 'Ymd', 'd M Y', 'd-M-Y'] as $format) {
            try {
                $parsed = CarbonImmutable::createFromFormat($format, $rawDob);
            } catch (\Throwable) {
                continue;
            }
            if ($parsed instanceof CarbonImmutable) {
                return $parsed;
            }
        }

        if (preg_match('/^\d{4}$/', $rawDob) === 1) {
            try {
                return CarbonImmutable::create((int) $rawDob, 1, 1, 0, 0, 0);
            } catch (\Throwable) {
                return null;
            }
        }

        try {
            return CarbonImmutable::parse($rawDob);
        } catch (\Throwable) {
            return null;
        }
    }
}
