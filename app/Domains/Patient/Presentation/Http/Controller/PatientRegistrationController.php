<?php

declare(strict_types=1);

namespace App\Domains\Patient\Presentation\Http\Controller;

use App\Domains\Patient\Application\Command\AddPatientIdentifier;
use App\Domains\Patient\Application\Command\DuplicateCandidateView;
use App\Domains\Patient\Application\Command\RegisterPatient;
use App\Domains\Patient\Application\Command\RegisterPatientResult;
use App\Domains\Patient\Application\Command\RegisterPatientReview;
use App\Domains\Patient\Application\Command\UpdatePatientAddress;
use App\Domains\Patient\Application\Command\VerifyPatientIdentifier;
use App\Domains\Patient\Application\RegistrationDraftService;
use App\Domains\Patient\Domain\Enum\AddressType;
use App\Domains\Patient\Domain\Enum\DobPrecision;
use App\Domains\Patient\Domain\Enum\IdentifierType;
use App\Domains\Patient\Domain\Enum\Sex;
use App\Domains\Patient\Domain\Exception\PatientRegistrationBlocked;
use App\Domains\Patient\Presentation\Http\Request\RegisterPatientRequest;
use App\Models\User;
use App\Platform\Documents\Contracts\DocumentsApi;
use App\Platform\Documents\Contracts\DTO\UploadDocumentDto;
use App\Platform\Documents\Domain\Enum\ConfidentialityLevel;
use App\Platform\Documents\Domain\Enum\DocumentType;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class PatientRegistrationController
{
    private const DRAFT_FIELDS = [
        'given_name', 'middle_name', 'family_name', 'display_name', 'sex', 'dob_precision',
        'date_of_birth', 'estimated_years', 'estimated_months', 'estimated_days', 'primary_phone',
        'email', 'national_id', 'insurance_member_number', 'region', 'district', 'ward',
        'street_or_village', 'postal_code', 'provisional', 'after_registration', 'override_duplicate', 'override_reason',
        'return_to_reception', 'return_date', 'return_status', 'return_filter',
    ];

    private const AFTER_REGISTRATION_DESTINATIONS = ['FRONT_DESK', 'PATIENT_CHART', 'PATIENT_LIST'];

    public function create(Request $request, TenantContext $context, RegistrationDraftService $drafts): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        abort_unless($user->can('patient.patients.create'), 403, 'Unauthorized.');

        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');

        $draftValues = $drafts->find((string) $context->tenantId(), $facilityId, (string) $user->getKey());
        $requestedDestination = $request->query('after_registration');
        $defaultAfterRegistration = in_array($requestedDestination, self::AFTER_REGISTRATION_DESTINATIONS, true)
            ? $requestedDestination
            : ($request->boolean('return_to_reception') || $user->can('encounter.encounters.create')
                ? 'FRONT_DESK'
                : 'PATIENT_LIST');
        if (is_array($draftValues)
            && ! in_array($draftValues['after_registration'] ?? null, self::AFTER_REGISTRATION_DESTINATIONS, true)) {
            $draftValues['after_registration'] = ! empty($draftValues['return_to_reception'])
                ? 'FRONT_DESK'
                : $defaultAfterRegistration;
        }

        return Inertia::render('Patients/Register', [
            'duplicateReview' => null,
            'formValues' => is_array($draftValues) ? $draftValues : self::receptionReturnValues($request),
            'hasSavedDraft' => $draftValues !== null,
            'defaultAfterRegistration' => is_array($draftValues)
                ? $draftValues['after_registration']
                : $defaultAfterRegistration,
        ]);
    }

    public function saveDraft(Request $request, TenantContext $context, RegistrationDraftService $drafts): JsonResponse
    {
        [$user, $facilityId] = $this->registrationActor($request, $context);

        $validated = $request->validate([
            'values' => ['required', 'array'],
            'values.given_name' => ['nullable', 'string', 'max:100'],
            'values.middle_name' => ['nullable', 'string', 'max:100'],
            'values.family_name' => ['nullable', 'string', 'max:100'],
            'values.display_name' => ['nullable', 'string', 'max:255'],
            'values.sex' => ['nullable', 'string', 'in:MALE,FEMALE,UNKNOWN'],
            'values.dob_precision' => ['nullable', 'string', 'in:KNOWN,ESTIMATED,UNKNOWN'],
            'values.date_of_birth' => ['nullable', 'date_format:Y-m-d'],
            'values.estimated_years' => ['nullable', 'integer', 'min:0', 'max:130'],
            'values.estimated_months' => ['nullable', 'integer', 'min:0', 'max:11'],
            'values.estimated_days' => ['nullable', 'integer', 'min:0', 'max:31'],
            'values.primary_phone' => ['nullable', 'string', 'max:30'],
            'values.email' => ['nullable', 'email', 'max:255'],
            'values.national_id' => ['nullable', 'string', 'max:50'],
            'values.insurance_member_number' => ['nullable', 'string', 'max:50'],
            'values.region' => ['nullable', 'string', 'max:255'],
            'values.district' => ['nullable', 'string', 'max:255'],
            'values.ward' => ['nullable', 'string', 'max:255'],
            'values.street_or_village' => ['nullable', 'string', 'max:255'],
            'values.postal_code' => ['nullable', 'string', 'max:255'],
            'values.provisional' => ['nullable', 'boolean'],
            'values.after_registration' => ['nullable', 'string', 'in:FRONT_DESK,PATIENT_CHART,PATIENT_LIST'],
            'values.override_duplicate' => ['nullable', 'boolean'],
            'values.override_reason' => ['nullable', 'string', 'max:500'],
            'values.return_to_reception' => ['nullable', 'boolean'],
            'values.return_date' => ['nullable', 'date_format:Y-m-d'],
            'values.return_status' => ['nullable', 'string', 'max:100'],
            'values.return_filter' => ['nullable', 'string', 'max:255'],
        ]);

        $values = array_intersect_key($validated['values'], array_fill_keys(self::DRAFT_FIELDS, true));

        if (($values['override_duplicate'] ?? false) && ! $user->can('patient.patients.override_duplicate')) {
            abort(403, 'Unauthorized.');
        }

        return response()->json($drafts->save(
            (string) $context->tenantId(),
            $facilityId,
            (string) $user->getKey(),
            $values,
        ));
    }

    public function deleteDraft(Request $request, TenantContext $context, RegistrationDraftService $drafts): JsonResponse
    {
        [$user, $facilityId] = $this->registrationActor($request, $context);

        $drafts->delete((string) $context->tenantId(), $facilityId, (string) $user->getKey());

        return response()->json(['deleted' => true]);
    }

    public function store(
        RegisterPatientRequest $request,
        TenantContext $context,
        CommandBus $commandBus,
        DocumentsApi $documents,
        RegistrationDraftService $drafts,
    ): Response|RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        abort_unless($user->can('patient.patients.create'), 403, 'Unauthorized.');

        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');

        $patientId = (string) Str::uuid7();
        $actorId = (string) $user->getKey();

        $photoDocumentId = null;
        if ($request->hasFile('photo')) {
            $photo = $request->file('photo');
            try {
                $document = $documents->upload(new UploadDocumentDto(
                    tenantId: (string) $context->tenantId(),
                    facilityId: $facilityId,
                    docType: DocumentType::PATIENT_PHOTO,
                    confidentialityLevel: ConfidentialityLevel::STANDARD,
                    sourceDomain: 'PATIENT',
                    sourceEntityRef: $patientId,
                    fileContents: (string) $photo->get(),
                    mimeType: (string) $photo->getMimeType(),
                    fileName: (string) $photo->getClientOriginalName(),
                    actorId: $actorId,
                    patientId: $patientId,
                ));
                $photoDocumentId = $document->id;
            } catch (\Throwable) {
                return back()
                    ->with('error', __('Patient photo could not be stored. No patient was registered.'))
                    ->withInput($request->except(['photo']));
            }
        }

        $overrideDuplicate = (bool) $request->boolean('override_duplicate');
        $overrideReason = $overrideDuplicate ? trim((string) $request->input('override_reason')) : null;
        if ($overrideReason === '') {
            $overrideReason = null;
        }

        $idempotencyKey = $request->header('Idempotency-Key');
        $idempotencyKey = is_string($idempotencyKey) && trim($idempotencyKey) !== '' ? trim($idempotencyKey) : null;

        $dobPrecision = DobPrecision::from((string) $request->input('dob_precision'));
        $dateOfBirth = $request->filled('date_of_birth') ? (string) $request->input('date_of_birth') : null;

        if ($dobPrecision === DobPrecision::Estimated && $dateOfBirth === null) {
            $years = (int) $request->input('estimated_years', 0);
            $months = (int) $request->input('estimated_months', 0);
            $days = (int) $request->input('estimated_days', 0);

            if ($years > 0 || $months > 0 || $days > 0) {
                $dateOfBirth = now()
                    ->subYears($years)
                    ->subMonths($months)
                    ->subDays($days)
                    ->toDateString();
            }
        }

        $command = new RegisterPatient(
            patientId: $patientId,
            actorId: (string) $user->getKey(),
            givenName: (string) $request->input('given_name'),
            familyName: (string) $request->input('family_name'),
            middleName: $request->filled('middle_name') ? (string) $request->input('middle_name') : null,
            displayName: $request->filled('display_name') ? (string) $request->input('display_name') : null,
            sex: Sex::from((string) $request->input('sex')),
            dateOfBirth: $dateOfBirth,
            dobPrecision: $dobPrecision,
            primaryPhone: $request->filled('primary_phone') ? (string) $request->input('primary_phone') : null,
            email: $request->filled('email') ? (string) $request->input('email') : null,
            provisional: (bool) $request->boolean('provisional'),
            source: 'RECEPTION',
            idempotencyKey: $idempotencyKey,
            nationalId: $request->filled('national_id') ? (string) $request->input('national_id') : null,
            insuranceMemberNumber: $request->filled('insurance_member_number') ? (string) $request->input('insurance_member_number') : null,
            overrideDuplicate: $overrideDuplicate,
            overrideReason: $overrideReason,
            photoDocumentId: $photoDocumentId,
        );

        try {
            $result = $commandBus->dispatch($command);
        } catch (PatientRegistrationBlocked $e) {
            // Single home for the collision message: the DuplicateResolutionDialog
            // (driven by the duplicate_collision flash below). Do NOT also put the
            // message in the validation error bag — it renders under the form
            // field and shows the same sentence twice.
            $duplicateCollisionData = [
                'message' => $e->getMessage(),
                'collidingField' => $e->collidingField,
                'candidateSummary' => $e->candidateSummary,
                'confidenceBand' => $e->confidenceBand,
                'outcome' => 'BLOCK',
                'candidates' => array_map(static fn (DuplicateCandidateView $c): array => [
                    'patientId' => $c->patientId,
                    'patientNumber' => $c->patientNumber,
                    'displayName' => $c->displayName,
                    'dateOfBirth' => $c->dateOfBirth,
                ], $e->candidates),
                'draft' => $request->except(['_token']),
            ];

            return back()
                ->with('error', $e->getMessage())
                ->with('duplicate_collision', $duplicateCollisionData);
        }

        if ($result instanceof RegisterPatientResult) {
            $patientId = $result->patientId;
            $actorId = (string) $user->getKey();

            if ($user->can('patient.identifiers.manage')) {
                if ($request->filled('national_id')) {
                    $nationalId = trim((string) $request->input('national_id'));
                    if ($nationalId !== '') {
                        $identifierId = (string) Str::uuid7();
                        $commandBus->dispatch(new AddPatientIdentifier(
                            identifierId: $identifierId,
                            patientId: $patientId,
                            actorId: $actorId,
                            type: IdentifierType::NationalId,
                            value: $nationalId,
                        ));
                        $commandBus->dispatch(new VerifyPatientIdentifier(
                            identifierId: $identifierId,
                            actorId: $actorId,
                            verificationSource: 'RECEPTION_REGISTRATION',
                        ));
                    }
                }

                if ($request->filled('insurance_member_number')) {
                    $insuranceNumber = trim((string) $request->input('insurance_member_number'));
                    if ($insuranceNumber !== '') {
                        $identifierId = (string) Str::uuid7();
                        $commandBus->dispatch(new AddPatientIdentifier(
                            identifierId: $identifierId,
                            patientId: $patientId,
                            actorId: $actorId,
                            type: IdentifierType::InsuranceMember,
                            value: $insuranceNumber,
                        ));
                        $commandBus->dispatch(new VerifyPatientIdentifier(
                            identifierId: $identifierId,
                            actorId: $actorId,
                            verificationSource: 'RECEPTION_REGISTRATION',
                        ));
                    }
                }
            }

            // Visit address at intake (Tanzanian visit workflow). Same pattern
            // as identifiers above: the upsert is authorized by
            // patient.patients.update (PatientAddressPolicy), so without it
            // the address is skipped rather than failing the registration.
            // Type is always RESIDENTIAL — intake records where the patient
            // lives, never postal/other. All-empty input writes no row.
            if ($user->can('patient.patients.update')) {
                $addressValues = [
                    'region' => $request->filled('region') ? trim((string) $request->input('region')) : null,
                    'district' => $request->filled('district') ? trim((string) $request->input('district')) : null,
                    'ward' => $request->filled('ward') ? trim((string) $request->input('ward')) : null,
                    'street_or_village' => $request->filled('street_or_village') ? trim((string) $request->input('street_or_village')) : null,
                    'postal_code' => $request->filled('postal_code') ? trim((string) $request->input('postal_code')) : null,
                ];
                $addressValues = array_filter($addressValues, static fn (?string $v): bool => $v !== null && $v !== '');

                if ($addressValues !== []) {
                    $commandBus->dispatch(new UpdatePatientAddress(
                        addressId: (string) Str::uuid7(),
                        patientId: $patientId,
                        actorId: $actorId,
                        type: AddressType::Residential,
                        region: $addressValues['region'] ?? null,
                        district: $addressValues['district'] ?? null,
                        ward: $addressValues['ward'] ?? null,
                        streetOrVillage: $addressValues['street_or_village'] ?? null,
                        postalCode: $addressValues['postal_code'] ?? null,
                    ));
                }
            }

            $drafts->delete((string) $context->tenantId(), $facilityId, (string) $user->getKey());

            if ($request->boolean('return_to_mpi')) {
                return redirect()->route('patient.index') // TEMP: chart page unbuilt (renders 500); restore patient.chart when Patients/Profile lands
                    ->with('status', __('Patient :number registered successfully.', ['number' => $result->patientNumber]))
                    ->with('registered_patient', [
                        'patientId' => $result->patientId,
                        'patientNumber' => $result->patientNumber,
                        'displayName' => (string) ($request->input('display_name') ?? trim((string) $request->input('given_name').' '.(string) $request->input('family_name'))),
                    ]);
            }

            $destination = $request->input('after_registration');
            if (! in_array($destination, self::AFTER_REGISTRATION_DESTINATIONS, true)) {
                $destination = $request->boolean('return_to_reception') ? 'FRONT_DESK' : 'PATIENT_LIST';
            }

            if ($destination === 'FRONT_DESK') {
                $params = array_merge(['patient_id' => $result->patientId], self::receptionReturnParams($request));

                return redirect()->route('reception.index', $params)
                    ->with('status', __('Patient :number registered successfully.', ['number' => $result->patientNumber]))
                    ->with('registered_patient', [
                        'patientId' => $result->patientId,
                        'patientNumber' => $result->patientNumber,
                        'displayName' => (string) ($request->input('display_name') ?? trim((string) $request->input('given_name').' '.(string) $request->input('family_name'))),
                    ]);
            }

            if ($destination === 'PATIENT_CHART') {
                return redirect()->route('patient.chart', [
                    'patientId' => $result->patientId,
                ])->with('status', __('Patient :number registered successfully.', ['number' => $result->patientNumber]));
            }

            return redirect()->route('patient.index')
                ->with('status', __('Patient :number registered successfully.', ['number' => $result->patientNumber]));
        }

        if ($result instanceof RegisterPatientReview) {
            $reviewData = [
                'message' => __('Potential duplicate patient detected (:count matching records). Review records or provide override reason.', ['count' => count($result->candidates)]),
                'collidingField' => null,
                'candidateSummary' => null,
                'confidenceBand' => $result->confidenceBand,
                'outcome' => $result->outcome->value,
                'candidates' => array_map(static fn (DuplicateCandidateView $c): array => [
                    'patientId' => $c->patientId,
                    'patientNumber' => $c->patientNumber,
                    'displayName' => $c->displayName,
                    'dateOfBirth' => $c->dateOfBirth,
                ], $result->candidates),
                'draft' => $request->except(['_token']),
            ];

            // Render in place so duplicate review does not discard either
            // the registration data or the selected post-registration route.
            return Inertia::render('Patients/Register', [
                'duplicateReview' => $reviewData,
                'formValues' => $request->except('_token'),
                'defaultAfterRegistration' => in_array($request->input('after_registration'), self::AFTER_REGISTRATION_DESTINATIONS, true)
                    ? $request->input('after_registration')
                    : ($request->boolean('return_to_reception') ? 'FRONT_DESK' : 'PATIENT_LIST'),
            ]);
        }

        return redirect()->route('patient.index'); // TEMP: reception board unwired; restore when Reception/Index lands
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function registrationActor(Request $request, TenantContext $context): array
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($user->can('patient.patients.create'), 403, 'Unauthorized.');

        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');

        return [$user, $facilityId];
    }

    /**
     * Front Desk return context for the registration round-trip. The register
     * page is entered as `/workspaces/patient/register?return_to_reception=1
     * &date=…&status=…&filter=…`; the board context rides the form as
     * `return_date/return_status/return_filter` POST fields and comes back
     * here for the reception redirect. Every value is re-validated at each
     * hop — an invalid value is dropped, never trusted.
     *
     * @return array<string, mixed>|null
     */
    private static function receptionReturnValues(Request $request): ?array
    {
        if (! $request->boolean('return_to_reception')) {
            return null;
        }

        $requestedDestination = $request->query('after_registration');
        $values = [
            'return_to_reception' => true,
            'after_registration' => in_array($requestedDestination, self::AFTER_REGISTRATION_DESTINATIONS, true)
                ? $requestedDestination
                : 'FRONT_DESK',
        ];
        foreach (self::receptionReturnParams($request, fromQuery: true) as $key => $value) {
            $values['return_'.$key] = $value;
        }

        return $values;
    }

    /**
     * Validated board context (`date`, `status`, `filter`) for the reception
     * redirect, read from the register query string on entry or the POST
     * body on save. Mirrors ReceptionController's own guards.
     *
     * @return array{date?: string, status?: string, filter?: string}
     */
    private static function receptionReturnParams(Request $request, bool $fromQuery = false): array
    {
        $source = static function (string $queryKey, string $bodyKey) use ($request, $fromQuery): mixed {
            return $fromQuery ? $request->query($queryKey) : $request->input($bodyKey);
        };

        $params = [];

        $date = $source('date', 'return_date');
        if (is_string($date) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            $params['date'] = $date;
        }

        $status = $source('status', 'return_status');
        if (is_string($status)) {
            $requestedStatuses = [];
            foreach (explode(',', $status) as $statusValue) {
                $statusValue = strtoupper(trim($statusValue));
                if (in_array($statusValue, ['BOOKED', 'ARRIVED', 'ACTIVE', 'COMPLETED'], true)) {
                    $requestedStatuses[$statusValue] = true;
                }
            }

            $validStatuses = array_values(array_filter(
                ['BOOKED', 'ARRIVED', 'ACTIVE', 'COMPLETED'],
                static fn (string $statusValue): bool => isset($requestedStatuses[$statusValue]),
            ));

            if (strtoupper(trim($status)) === 'ALL' || $validStatuses !== []) {
                $params['status'] = $validStatuses === [] ? 'ALL' : implode(',', $validStatuses);
            }
        }

        $filter = $source('filter', 'return_filter');
        if (is_string($filter) && trim($filter) !== '') {
            $params['filter'] = mb_substr(trim($filter), 0, 80);
        }

        return $params;
    }
}
