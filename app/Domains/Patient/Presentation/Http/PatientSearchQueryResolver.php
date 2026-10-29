<?php

declare(strict_types=1);

namespace App\Domains\Patient\Presentation\Http;

use App\Domains\Patient\Application\Query\SearchPatients;
use Illuminate\Http\Request;

final class PatientSearchQueryResolver
{
    public static function resolve(Request $request, int $page, int $perPage): ?SearchPatients
    {
        $q = self::filledString($request, 'q');
        $patientNumber = self::filledString($request, 'patient_number');
        $nationalId = self::filledString($request, 'national_id');
        $insuranceId = self::filledString($request, 'insurance_id');
        $phone = self::filledString($request, 'phone');
        $nameFragment = self::filledString($request, 'name_fragment');
        $dob = self::filledString($request, 'dob');

        if ($q !== null && $q !== '') {
            $trimmedQ = trim($q);
            $cleanDigits = (string) preg_replace('/\D+/', '', $trimmedQ);
            $isPatPrefix = str_starts_with(strtoupper($trimmedQ), 'PAT-') ||
                str_starts_with(strtoupper($trimmedQ), 'PAT ') ||
                preg_match('/^PAT\d+$/i', $trimmedQ);

            if ($isPatPrefix) {
                $patientNumber ??= $trimmedQ;
            } elseif (preg_match('/^#\d+$/', $trimmedQ)) {
                $patientNumber ??= ltrim($trimmedQ, '#');
            } elseif ($cleanDigits !== '' && strlen($cleanDigits) >= 9 && strlen($cleanDigits) <= 15 && (str_starts_with($trimmedQ, '+') || str_starts_with($trimmedQ, '0') || strlen($cleanDigits) >= 10)) {
                $phone ??= $cleanDigits;
                $nationalId ??= $trimmedQ;
            } else {
                if ($patientNumber === null && ctype_digit($trimmedQ) && strlen($trimmedQ) <= 8) {
                    $patientNumber = $trimmedQ;
                }
                if ($nationalId === null && preg_match('/[0-9]/', $trimmedQ)) {
                    $nationalId = $trimmedQ;
                }
                if ($insuranceId === null && preg_match('/[0-9]/', $trimmedQ)) {
                    $insuranceId = $trimmedQ;
                }
                $hasExact = $patientNumber !== null || $nationalId !== null || $insuranceId !== null || $phone !== null;
                if ($nameFragment === null && (! $hasExact || mb_strlen(str_replace(' ', '', $trimmedQ)) >= 3)) {
                    $nameFragment = $trimmedQ;
                }
            }
        }

        $hasExact = $patientNumber !== null || $nationalId !== null || $insuranceId !== null || $phone !== null;
        $hasCriteria = $hasExact || ($nameFragment !== null && mb_strlen(str_replace(' ', '', $nameFragment)) >= 2) || $dob !== null;

        if (! $hasCriteria) {
            return null;
        }

        return new SearchPatients(
            patientNumber: $patientNumber,
            nationalId: $nationalId,
            insuranceId: $insuranceId,
            phone: $phone,
            nameFragment: $nameFragment,
            dateOfBirth: $dob,
            page: $page,
            perPage: $perPage,
        );
    }

    private static function filledString(Request $request, string $key): ?string
    {
        return $request->filled($key) ? trim((string) $request->input($key)) : null;
    }
}
