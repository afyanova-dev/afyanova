<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Presentation\Http\Controller;

use App\Domains\Clinical\Application\Query\GetPatientAllergies;
use App\Domains\Clinical\Contracts\Dto\AllergySummary;
use App\Domains\Patient\Contracts\Dto\PatientSearchResult;
use App\Domains\Patient\Contracts\PatientApi;
use App\Domains\Pharmacy\Domain\ValueObject\PharmacyOrderStatus;
use App\Domains\Pharmacy\Domain\ValueObject\PrescriptionStatus;
use App\Domains\Pharmacy\Presentation\Http\DTO\RxPatientResult;
use App\Domains\Pharmacy\Presentation\Http\Presenter\PharmacyPresenter;
use App\Models\User;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

final class PharmacyController
{
    public function __invoke(
        Request $request,
        TenantContext $context,
        QueryBus $queryBus,
        PharmacyPresenter $presenter,
        PatientApi $patientApi,
    ): Response {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($user->can('pharmacy.access'), 403, 'Unauthorized.');

        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');

        $prescriptionStatus = strtoupper(trim((string) $request->query('rx_status', '')));
        $prescriptionStatus = PrescriptionStatus::isValid($prescriptionStatus) ? $prescriptionStatus : null;

        $orderStatus = strtoupper(trim((string) $request->query('order_status', '')));
        $orderStatus = PharmacyOrderStatus::isValid($orderStatus) ? $orderStatus : null;

        $selectedOrder = $request->query('order_id');
        $selectedOrder = is_string($selectedOrder) && Str::isUuid($selectedOrder) ? $selectedOrder : null;

        $selectedPrescription = $request->query('rx_id');
        $selectedPrescription = is_string($selectedPrescription) && Str::isUuid($selectedPrescription) ? $selectedPrescription : null;
        $externalIntake = $request->query('external_intake') === '1';

        // URL owns the view (plan: pharmacy-queue-stability). Exactly one
        // selection survives: an explicit order wins over a script, and the
        // tab mirrors whatever is selected.
        $view = strtolower(trim((string) $request->query('view', '')));
        $view = in_array($view, ['orders', 'prescriptions', 'patient'], true) ? $view : null;

        $viewPatient = $request->query('patient_id');
        $viewPatient = is_string($viewPatient) && Str::isUuid($viewPatient) ? $viewPatient : null;
        if ($externalIntake) {
            $viewPatient = null;
            if ($view === 'patient') {
                $view = 'orders';
            }
        }

        if ($selectedOrder !== null) {
            $selectedPrescription = null;
            $view = 'orders';
        } elseif ($selectedPrescription !== null) {
            $view = 'prescriptions';
        } elseif ($viewPatient !== null) {
            $view = 'patient';
        } else {
            $viewPatient = null;
            $view ??= 'orders';
        }

        $selectedItem = $request->query('item_id');
        $selectedItem = is_string($selectedItem) && Str::isUuid($selectedItem) ? $selectedItem : null;

        $patientId = $request->query('patient_id');
        $patientId = is_string($patientId) && Str::isUuid($patientId) ? $patientId : null;

        $query = $patientId === null && $request->filled('q') ? mb_substr(trim((string) $request->input('q')), 0, 80) : null;
        $searchResults = $query !== null && $query !== '' ? $this->search($patientApi, $query) : null;

        $transcribePatient = null;
        $transcribeAllergies = [];
        $transcribeAllergiesAvailable = $patientId === null;
        if ($patientId !== null) {
            try {
                $summary = $patientApi->getPatientSummary($patientId);
                $transcribePatient = [
                    'patientId' => $summary->patientId,
                    'patientNumber' => $summary->patientNumber,
                    'displayName' => $summary->displayName,
                ];
            } catch (Throwable) {
                $transcribePatient = null;
            }
            if ($transcribePatient !== null) {
                $transcribeAllergiesAvailable = true;
                try {
                    /** @var list<AllergySummary> $als */
                    $als = $queryBus->dispatch(new GetPatientAllergies($patientId, 'ACTIVE', null));
                    foreach ($als as $al) {
                        $transcribeAllergies[] = [
                            'substanceName' => $al->substanceName,
                            'severity' => $al->severity,
                            'reaction' => $al->reactionDescription,
                        ];
                    }
                } catch (Throwable) {
                    $transcribeAllergies = [];
                    $transcribeAllergiesAvailable = false;
                }
            } else {
                $transcribeAllergiesAvailable = false;
            }
        }

        $props = $presenter->present(
            facilityId: $facilityId,
            prescriptionStatus: $prescriptionStatus,
            orderStatus: $orderStatus,
            selectedOrderId: $selectedOrder,
            selectedPrescriptionId: $selectedPrescription,
            selectedItemId: $selectedItem,
            view: $view,
            viewPatientId: $viewPatient,
            permissions: [
                'transcribe' => $user->can('pharmacy.prescriptions.accept'),
                'orders' => $user->can('pharmacy.orders.manage'),
                'dispense' => $user->can('pharmacy.dispensing.record'),
                'reverse' => $user->can('pharmacy.dispensing.reverse'),
                'pickup' => $user->can('pharmacy.pickup.record'),
            ],
        );

        return Inertia::render('Pharmacy/Index', [
            'facilityId' => $props->facilityId,
            'canTranscribe' => $props->canTranscribe,
            'canManageOrders' => $props->canManageOrders,
            'canDispense' => $props->canDispense,
            'canReverse' => $props->canReverse,
            'canRecordPickup' => $props->canRecordPickup,
            'canCreatePharmacyPatient' => $user->can('patient.patients.create_pharmacy_only'),
            'externalIntake' => $externalIntake,
            'prescriptions' => $props->prescriptions,
            'prescriptionStatus' => $props->prescriptionStatus,
            'orders' => $props->orders,
            'orderStatus' => $props->orderStatus,
            'selectedOrderId' => $props->selectedOrderId,
            'selectedOrderNumber' => $props->selectedOrderNumber,
            'selectedOrderStatus' => $props->selectedOrderStatus,
            'selectedPrescriptionId' => $props->selectedPrescriptionId,
            'selectedPrescriptionNumber' => $props->selectedPrescriptionNumber,
            'selectedMedicationName' => $props->selectedMedicationName,
            'selectedMedicationCode' => $props->selectedMedicationCode,
            'selectedDose' => $props->selectedDose,
            'selectedFrequency' => $props->selectedFrequency,
            'selectedDuration' => $props->selectedDuration,
            'selectedPrescribedQuantity' => $props->selectedPrescribedQuantity,
            'selectedDosingInstruction' => $props->selectedDosingInstruction,
            'selectedPrescriberName' => $props->selectedPrescriberName,
            'selectedTranscriptionNote' => $props->selectedTranscriptionNote,
            'selectedRxStatus' => $props->selectedRxStatus,
            'selectedRxOccurredAt' => $props->selectedRxOccurredAt,
            'selectedSource' => $props->selectedSource,
            'selectedPatientId' => $props->selectedPatientId,
            'selectedPatientDisplayName' => $props->selectedPatientDisplayName,
            'selectedPatientNumber' => $props->selectedPatientNumber,
            'selectedPatientDateOfBirth' => $props->selectedPatientDateOfBirth,
            'selectedPatientSex' => $props->selectedPatientSex,
            'selectedPatientAllergies' => $props->selectedPatientAllergies,
            'dispensings' => $props->dispensings,
            'items' => $props->items,
            'locations' => $props->locations,
            'selectedItemId' => $props->selectedItemId,
            'selectedItemCode' => $props->selectedItemCode,
            'selectedItemName' => $props->selectedItemName,
            'selectedItemUom' => $props->selectedItemUom,
            'availabilityStatus' => $props->availabilityStatus,
            'stockByLocation' => $props->stockByLocation,
            'searchError' => $props->searchError,
            'query' => $query,
            'searchResults' => $searchResults,
            'view' => $props->view,
            'viewPatient' => $props->viewPatient,
            'transcribePatient' => $transcribePatient,
            'transcribeAllergies' => $transcribeAllergies,
            'transcribeAllergiesAvailable' => $transcribeAllergiesAvailable,
        ]);
    }

    /**
     * MPI lookup for the transcribe-patient search — same classification as
     * the other intake consoles (patient number / phone / name fragment).
     *
     * @return list<RxPatientResult>|null
     */
    private function search(PatientApi $patientApi, string $query): ?array
    {
        $cleanDigits = (string) preg_replace('/\D+/', '', $query);
        $isPatPrefix = str_starts_with(strtoupper($query), 'PAT-')
            || str_starts_with(strtoupper($query), 'PAT ')
            || preg_match('/^PAT\d+$/i', $query) === 1;

        $patientNumber = null;
        $phone = null;
        $nameFragment = null;

        if ($isPatPrefix || preg_match('/^#\d+$/', $query) === 1) {
            $patientNumber = ltrim($query, '#');
        } elseif ($cleanDigits !== '' && strlen($cleanDigits) >= 9 && (str_starts_with($query, '+') || str_starts_with($query, '0') || strlen($cleanDigits) >= 10)) {
            $phone = $cleanDigits;
        } elseif (mb_strlen(str_replace(' ', '', $query)) >= 3) {
            $nameFragment = $query;
        } else {
            return [];
        }

        try {
            /** @var LengthAwarePaginator<int, PatientSearchResult> $paginator */
            $paginator = $patientApi->searchPatients(
                patientNumber: $patientNumber,
                phone: $phone,
                nameFragment: $nameFragment,
                perPage: 10,
            );
        } catch (Throwable) {
            return [];
        }

        return array_values(array_map(static fn (PatientSearchResult $r): RxPatientResult => new RxPatientResult(
            patientId: $r->patientId,
            patientNumber: $r->patientNumber,
            displayName: $r->displayName,
            sex: $r->sex,
            status: $r->status,
            isProvisional: $r->isProvisional,
            hasAccessibleFacilityRegistration: $r->hasAccessibleFacilityRegistration,
            survivingPatientId: $r->survivingPatientId,
        ), $paginator->items()));
    }
}
