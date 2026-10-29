<?php

declare(strict_types=1);

namespace App\Domains\Patient\Presentation\Http\Controller;

use App\Domains\Patient\Application\Query\GetPatientGlance;
use App\Domains\Patient\Contracts\Dto\PatientGlance;
use App\Domains\Patient\Contracts\Dto\PatientGlanceIdentifier;
use App\Models\User;
use App\Shared\Bus\QueryBus;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PatientGlanceController
{
    public function __invoke(
        string $patientId,
        Request $request,
        TenantContext $context,
        QueryBus $queryBus,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        abort_unless($user->can('patient.patients.read'), 403, 'Unauthorized.');

        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');

        try {
            /** @var PatientGlance $glance */
            $glance = $queryBus->dispatch(new GetPatientGlance($patientId, $facilityId));
        } catch (NotFound) {
            abort(404, 'Patient not found.');
        }

        $canViewSensitive = $user->can('patient.identifiers.view_sensitive');

        return response()->json([
            'patientId' => $glance->patientId,
            'patientNumber' => $glance->patientNumber,
            'displayName' => $glance->displayName,
            'givenName' => $glance->givenName,
            'familyName' => $glance->familyName,
            'middleName' => $glance->middleName,
            'sex' => $glance->sex,
            'status' => $glance->status,
            'isProvisional' => $glance->isProvisional,
            'dateOfBirth' => $glance->dateOfBirth,
            'dobPrecision' => $glance->dobPrecision,
            'age' => $this->formatAge($glance->dateOfBirth),
            'primaryPhone' => $glance->primaryPhone,
            'email' => $glance->email,
            'registrationSource' => $glance->registrationSource,
            'registeredAt' => $glance->registeredAt,
            'hasAccessibleFacilityRegistration' => $glance->hasAccessibleFacilityRegistration,
            'facilityRegistrationCount' => $glance->facilityRegistrationCount,
            'survivingPatientId' => $glance->survivingPatientId,
            'identifiers' => array_map(
                fn (PatientGlanceIdentifier $id): array => [
                    'id' => $id->id,
                    'type' => $id->type,
                    'label' => match ($id->type) {
                        'NATIONAL_ID' => 'National ID / Passport',
                        'INSURANCE_MEMBER' => 'Insurance Member ID',
                        default => ucwords(strtolower(str_replace('_', ' ', $id->type))),
                    },
                    'value' => $canViewSensitive ? $id->value : $this->mask($id->value),
                    'isVerified' => $id->isVerified,
                ],
                $glance->identifiers,
            ),
        ]);
    }

    private function formatAge(?string $dateOfBirth): ?string
    {
        if ($dateOfBirth === null) {
            return null;
        }

        $dob = CarbonImmutable::parse($dateOfBirth);
        $diff = $dob->diff(CarbonImmutable::now());

        if ($diff->y >= 2) {
            return "{$diff->y} yrs";
        }
        if ($diff->y === 1) {
            return $diff->m > 0 ? "1 yr {$diff->m} mo" : '1 yr';
        }
        if ($diff->m > 0) {
            return $diff->d > 0 ? "{$diff->m} mo {$diff->d} d" : "{$diff->m} mo";
        }

        return "{$diff->d} days";
    }

    private function mask(string $value): string
    {
        return mb_strlen($value) > 4
            ? str_repeat('•', max(0, mb_strlen($value) - 4)).mb_substr($value, -4)
            : $value;
    }
}
