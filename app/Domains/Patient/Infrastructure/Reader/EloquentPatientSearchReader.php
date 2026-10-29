<?php

declare(strict_types=1);

namespace App\Domains\Patient\Infrastructure\Reader;

use App\Domains\Patient\Contracts\Dto\PatientSearchResult;
use App\Domains\Patient\Domain\Enum\IdentifierVerificationStatus;
use App\Domains\Patient\Domain\Enum\MergeHistoryStatus;
use App\Domains\Patient\Domain\Enum\PatientStatus;
use App\Domains\Patient\Domain\Model\Patient;
use App\Domains\Patient\Domain\PatientSearchReader;
use App\Domains\Patient\Domain\Search\PatientSearchCriteria;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Bounded, database-side patient search for {@see PatientSearchReader}
 * (10-patient-domain.md §16, §52, §126–§128; ADR-114, ADR-123, ADR-129).
 *
 * The search set is patients registered at the active facility, within the
 * current tenant. Filtering AND ranking happen in Postgres (ADR-129 D), and
 * the reader returns one bounded page of PHI-minimal DTOs.
 *
 * Column folding mirrors {@see PatientSearchCriteria} exactly so a match is
 * "exact on the normalized form" — no fuzzy / trigram / external search
 * (ADR-129 D). Every raw fragment is a compile-time literal with `?`
 * placeholders; dynamic value lists go through the query builder.
 */
final class EloquentPatientSearchReader implements PatientSearchReader
{
    /** Postgres: fold to alphanumerics-only uppercase (patient number). */
    private const FOLD_ALNUM = "upper(regexp_replace(%s, '[^A-Za-z0-9]', '', 'g'))";

    /** Postgres: fold a name — uppercase, each run of non-alphanumerics -> one space, trimmed. */
    private const FOLD_NAME = "btrim(regexp_replace(upper(%s), '[^A-Z0-9]+', ' ', 'g'))";

    /** Postgres: reduce a phone column to its digits. */
    private const PHONE_DIGITS = "regexp_replace(coalesce(%s, ''), '\\D', '', 'g')";

    /** A uuid that never exists — keeps a `whereIn` valid + matching nothing. */
    private const NO_FACILITY = '00000000-0000-0000-0000-000000000000';

    public function __construct(private readonly ScopeChecker $scope) {}

    public function search(
        PatientSearchCriteria $criteria,
        string $searchingUserId,
        string $facilityId,
        int $page,
        int $perPage,
    ): LengthAwarePaginatorContract {
        $foldedNumber = sprintf(self::FOLD_ALNUM, 'patient_number');
        $foldedFamily = sprintf(self::FOLD_NAME, 'family_name');
        $foldedGiven = sprintf(self::FOLD_NAME, 'given_name');
        $foldedDisplay = sprintf(self::FOLD_NAME, 'display_name');
        $foldedName = sprintf(self::FOLD_NAME, "given_name || ' ' || family_name");
        $phoneDigits = sprintf(self::PHONE_DIGITS, 'primary_phone');

        $verified = IdentifierVerificationStatus::Verified->value;
        $activeMerge = MergeHistoryStatus::Active->value;
        $accessibleFacilityIds = $this->accessibleFacilityIds($searchingUserId);

        $identifierMatch = 'exists (select 1 from pat_patient_identifiers ppi '
            ."where ppi.patient_id = pat_patients.id and ppi.verification_status = '{$verified}' "
            .'and ppi.normalized_value = ?)';

        // ── deterministic tiered rank (ADR-129 D), highest first ────────────
        $rankSql = ['0'];
        $rankBindings = [];

        $patNumberDigits = $criteria->patientNumber !== null ? preg_replace('/\D+/', '', $criteria->patientNumber) : null;
        $paddedPatNumber = ($patNumberDigits !== null && $patNumberDigits !== '') ? sprintf('PAT%08d', (int) $patNumberDigits) : null;

        if ($criteria->patientNumber !== null) {
            if ($paddedPatNumber !== null) {
                $rankSql[] = "case when {$foldedNumber} = ? or {$foldedNumber} = ? then 600 when {$foldedNumber} like ? then 570 when {$foldedNumber} like ? then 550 else 0 end";
                $rankBindings[] = $criteria->patientNumber;
                $rankBindings[] = $paddedPatNumber;
                $rankBindings[] = $criteria->patientNumber.'%';
                $rankBindings[] = '%'.$criteria->patientNumber;
            } elseif (mb_strlen($criteria->patientNumber) >= 3) {
                $rankSql[] = "case when {$foldedNumber} = ? then 600 when {$foldedNumber} like ? then 570 when {$foldedNumber} like ? then 550 else 0 end";
                $rankBindings[] = $criteria->patientNumber;
                $rankBindings[] = $criteria->patientNumber.'%';
                $rankBindings[] = '%'.$criteria->patientNumber;
            } else {
                $rankSql[] = "case when {$foldedNumber} = ? then 600 else 0 end";
                $rankBindings[] = $criteria->patientNumber;
            }
        }
        if ($criteria->nationalId !== null) {
            $rankSql[] = "case when {$identifierMatch} then 500 else 0 end";
            $rankBindings[] = $criteria->nationalId;
        }
        if ($criteria->insuranceId !== null) {
            $rankSql[] = "case when {$identifierMatch} then 500 else 0 end";
            $rankBindings[] = $criteria->insuranceId;
        }
        if ($criteria->phone !== null) {
            $rankSql[] = "case when {$phoneDigits} = ? then 400 else 0 end";
            $rankBindings[] = $criteria->phone;
        }
        if ($criteria->nameFragment !== null) {
            $rankSql[] = "case when {$foldedFamily} like ? then 300 else 0 end";
            $rankBindings[] = $criteria->nameFragment.'%';
            $rankSql[] = "case when {$foldedGiven} like ? or {$foldedDisplay} like ? then 200 else 0 end";
            $rankBindings[] = $criteria->nameFragment.'%';
            $rankBindings[] = $criteria->nameFragment.'%';
            $rankSql[] = "case when {$foldedName} like ? then 100 else 0 end";
            $rankBindings[] = '%'.$criteria->nameFragment.'%';
        }

        $rankExpr = 'greatest('.implode(', ', $rankSql).')';
        if ($criteria->dateOfBirth !== null) {
            // A supplied DOB that matches bumps the row WITHIN its tier (tiers are 100 apart).
            $rankExpr .= ' + (case when date_of_birth = ? then 10 else 0 end)';
            $rankBindings[] = $criteria->dateOfBirth;
        }

        $query = Patient::query()
            ->select('pat_patients.*')
            ->selectRaw("({$rankExpr}) as search_rank", $rankBindings)
            ->addSelect(['has_accessible_facility_registration' => fn (Builder $sub) => $sub
                ->selectRaw('1')
                ->from('pat_patient_registrations as ppr')
                ->whereColumn('ppr.patient_id', 'pat_patients.id')
                ->whereIn('ppr.facility_id', $accessibleFacilityIds)
                ->limit(1)])
            ->addSelect(['resolved_surviving_patient_id' => fn (Builder $sub) => $sub
                ->select('h.surviving_patient_id')
                ->from('pat_patient_identity_history as h')
                ->whereColumn('h.non_surviving_patient_id', 'pat_patients.id')
                ->where('h.status', $activeMerge)
                ->orderByDesc('h.merged_at')
                ->limit(1)])
            ->where(function (Builder $q) use (
                $criteria,
                $identifierMatch,
                $foldedNumber,
                $foldedName,
                $phoneDigits,
                $paddedPatNumber,
            ): void {
                if ($criteria->patientNumber !== null) {
                    if ($paddedPatNumber !== null) {
                        $q->orWhereRaw("({$foldedNumber} = ? or {$foldedNumber} = ? or {$foldedNumber} like ? or {$foldedNumber} like ?)", [
                            $criteria->patientNumber,
                            $paddedPatNumber,
                            $criteria->patientNumber.'%',
                            '%'.$criteria->patientNumber,
                        ]);
                    } elseif (mb_strlen($criteria->patientNumber) >= 3) {
                        $q->orWhereRaw("({$foldedNumber} = ? or {$foldedNumber} like ? or {$foldedNumber} like ?)", [
                            $criteria->patientNumber,
                            $criteria->patientNumber.'%',
                            '%'.$criteria->patientNumber,
                        ]);
                    } else {
                        $q->orWhereRaw("{$foldedNumber} = ?", [$criteria->patientNumber]);
                    }
                }
                if ($criteria->nationalId !== null) {
                    $q->orWhereRaw($identifierMatch, [$criteria->nationalId]);
                }
                if ($criteria->insuranceId !== null) {
                    $q->orWhereRaw($identifierMatch, [$criteria->insuranceId]);
                }
                if ($criteria->phone !== null) {
                    $q->orWhereRaw("{$phoneDigits} = ?", [$criteria->phone]);
                }
                if ($criteria->nameFragment !== null) {
                    $q->orWhereRaw("{$foldedName} like ?", ['%'.$criteria->nameFragment.'%']);
                }
            })
            ->whereExists(static fn (Builder $sub) => $sub
                ->selectRaw('1')
                ->from('pat_patient_registrations as active_facility_registration')
                ->whereColumn('active_facility_registration.patient_id', 'pat_patients.id')
                ->where('active_facility_registration.facility_id', $facilityId))
            ->orderByRaw('search_rank desc')
            ->orderBy('patient_number');

        $paginated = $query->paginate($perPage, ['*'], 'page', $page);

        /** @var list<PatientSearchResult> $rows */
        $rows = array_map(
            fn (Patient $patient): PatientSearchResult => new PatientSearchResult(
                patientId: (string) $patient->id,
                patientNumber: (string) $patient->patient_number,
                displayName: (string) $patient->display_name,
                sex: $patient->sex->value,
                status: $patient->status->value,
                isProvisional: (bool) $patient->is_provisional,
                hasAccessibleFacilityRegistration: $patient->getAttribute('has_accessible_facility_registration') !== null,
                survivingPatientId: $this->survivingPatientId($patient),
            ),
            $paginated->items(),
        );

        return new LengthAwarePaginator(
            $rows,
            $paginated->total(),
            $paginated->perPage(),
            $paginated->currentPage(),
            ['path' => LengthAwarePaginator::resolveCurrentPath(), 'pageName' => 'page'],
        );
    }

    /**
     * The facilities the searching user can reach — "the caller's ScopeChecker
     * scope" (ADR-129 E). A tenant-/org-wide caller gets every reachable
     * facility, so every result's flag is true. Never empty at the SQL level.
     *
     * @return non-empty-list<string>
     */
    private function accessibleFacilityIds(string $searchingUserId): array
    {
        $user = User::query()->find($searchingUserId);
        $ids = $user instanceof User ? $this->scope->selectableFacilities($user) : [];

        return $ids !== [] ? $ids : [self::NO_FACILITY];
    }

    private function survivingPatientId(Patient $patient): ?string
    {
        if ($patient->status !== PatientStatus::Merged) {
            return null;
        }

        $resolved = $patient->getAttribute('resolved_surviving_patient_id');

        return $resolved !== null ? (string) $resolved : null;
    }
}
