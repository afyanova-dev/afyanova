<?php

declare(strict_types=1);

namespace App\Domains\Patient\Infrastructure\Reader;

use App\Domains\Patient\Domain\DuplicateCandidateReader;
use App\Domains\Patient\Domain\Enum\IdentifierVerificationStatus;
use App\Domains\Patient\Domain\Enum\PatientStatus;
use App\Domains\Patient\Domain\Model\Patient;
use App\Domains\Patient\Domain\Model\PatientIdentifier;
use App\Domains\Patient\Domain\Service\DuplicateCandidate;
use App\Domains\Patient\Domain\Service\DuplicateSignals;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Bounded candidate fetch for {@see DuplicateCandidateReader} (ADR-117).
 *
 * Runs inside the RegisterPatient command transaction, so RLS + the
 * BelongsToTenant scope already confine every query to the current tenant —
 * the tenant-wide MPI (ADR-114), never cross-tenant. Two sources are unioned:
 *
 *   1. demographic near-matches — an exact hit on date of birth, folded family
 *      name, or phone digits (a strong single signal; the detector does the
 *      real scoring in PHP so the SQL only has to avoid missing a true match);
 *   2. VERIFIED national / insurance identifier collisions — an exact match on
 *      pat_patient_identifiers, which ADR-117 makes a BLOCK signal on its own.
 */
final class EloquentDuplicateCandidateReader implements DuplicateCandidateReader
{
    private const MAX_DEMOGRAPHIC_CANDIDATES = 50;

    /** Postgres expression folding a column to alphanumerics-only uppercase. */
    private const FOLD = "upper(regexp_replace(%s, '[^A-Za-z0-9]', '', 'g'))";

    public function candidatesFor(DuplicateSignals $signals): array
    {
        $identifierMatchIds = $this->verifiedIdentifierMatchIds($signals);

        /** @var array<string, Patient> $patients keyed by id */
        $patients = [];

        foreach ($this->demographicMatches($signals) as $patient) {
            $patients[(string) $patient->id] = $patient;
        }

        foreach ($identifierMatchIds as $id) {
            if (! isset($patients[$id])) {
                $patient = Patient::query()->whereKey($id)->first();
                if ($patient !== null) {
                    $patients[$id] = $patient;
                }
            }
        }

        // ADR-128 M-10 — a MERGED patient is never a duplicate candidate (its
        // identity has been consolidated into a survivor). Filters both the
        // demographic and the verified-identifier match paths.
        $patients = array_filter(
            $patients,
            static fn (Patient $patient): bool => $patient->status !== PatientStatus::Merged,
        );

        return array_values(array_map(
            fn (Patient $patient): DuplicateCandidate => new DuplicateCandidate(
                patientId: (string) $patient->id,
                patientNumber: $patient->patient_number,
                displayName: $patient->display_name,
                dateOfBirth: $patient->date_of_birth?->toDateString(),
                normalizedName: DuplicateSignals::fold($patient->given_name.' '.$patient->family_name),
                normalizedFamilyName: DuplicateSignals::fold($patient->family_name),
                normalizedPhone: $this->phoneDigits($patient->primary_phone),
                sex: $patient->sex->value,
                hasVerifiedIdentifierMatch: in_array((string) $patient->id, $identifierMatchIds, true),
            ),
            $patients,
        ));
    }

    /**
     * @return list<Patient>
     */
    private function demographicMatches(DuplicateSignals $signals): array
    {
        $familyKey = str_replace(' ', '', $signals->normalizedFamilyName);
        $phone = $signals->normalizedPhone;

        /** @var list<Patient> */
        return Patient::query()
            ->where('status', '<>', PatientStatus::Merged->value) // ADR-128 M-10
            ->where(function (Builder $q) use ($signals, $familyKey, $phone): void {
                $q->whereRaw(sprintf(self::FOLD, 'family_name').' = ?', [$familyKey]);

                if ($signals->dateOfBirth !== null) {
                    $q->orWhere('date_of_birth', $signals->dateOfBirth);
                }

                if ($phone !== null) {
                    $q->orWhereRaw("regexp_replace(coalesce(primary_phone, ''), '\\D', '', 'g') = ?", [$phone]);
                }
            })
            ->limit(self::MAX_DEMOGRAPHIC_CANDIDATES)
            ->get()
            ->all();
    }

    /**
     * @return list<string> patient ids with a VERIFIED national/insurance
     *                      identifier equal to one the registration presented
     */
    private function verifiedIdentifierMatchIds(DuplicateSignals $signals): array
    {
        $values = array_values(array_filter([
            $signals->normalizedNationalId,
            $signals->normalizedInsuranceId,
        ]));

        if ($values === []) {
            return [];
        }

        /** @var list<string> */
        return PatientIdentifier::query()
            ->where('verification_status', IdentifierVerificationStatus::Verified->value)
            ->whereIn('normalized_value', $values)
            ->pluck('patient_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
    }

    private function phoneDigits(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }
        $digits = (string) preg_replace('/\D+/', '', $phone);

        return $digits === '' ? null : $digits;
    }
}
