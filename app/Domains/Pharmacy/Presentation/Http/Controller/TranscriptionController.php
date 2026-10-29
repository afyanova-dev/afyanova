<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Presentation\Http\Controller;

use App\Domains\Inventory\Contracts\InventoryApi;
use App\Domains\Patient\Contracts\Dto\PharmacyOnlyPatientRegistrationOutcome;
use App\Domains\Patient\Contracts\PatientApi;
use App\Domains\Pharmacy\Application\TranscriptionBatchService;
use App\Domains\Pharmacy\Presentation\Http\Request\RegisterExternalPharmacyPatientRequest;
use App\Domains\Pharmacy\Presentation\Http\Request\TranscribePaperPrescriptionRequest;
use App\Models\User;
use App\Shared\Domain\DomainException;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

final class TranscriptionController
{
    public function registerExternalPatient(
        RegisterExternalPharmacyPatientRequest $request,
        TenantContext $context,
        PatientApi $patientApi,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        abort_if($context->facilityId() === null || $context->facilityId() === '', 403, 'No active facility context.');

        try {
            $outcome = $patientApi->registerPharmacyOnlyPatient(
                patientId: (string) Str::uuid7(),
                actorId: (string) $user->getAuthIdentifier(),
                givenName: trim((string) $request->input('given_name')),
                familyName: trim((string) $request->input('family_name')),
                sex: (string) $request->input('sex'),
                primaryPhone: $request->filled('primary_phone') ? trim((string) $request->input('primary_phone')) : null,
            );
        } catch (AuthorizationException) {
            return back()->withErrors(['patient' => 'Not authorized to create a pharmacy-only patient record.']);
        } catch (DomainException $e) {
            return back()->withErrors(['patient' => $e->getMessage()]);
        }

        if ($outcome->status === PharmacyOnlyPatientRegistrationOutcome::REVIEW_REQUIRED) {
            return back()->withErrors([
                'patient' => 'A possible duplicate patient was found. Search for and select the existing patient instead.',
            ]);
        }

        if ($outcome->patientId === null) {
            throw new \LogicException('Created pharmacy patient outcome did not include a patient id.');
        }

        return redirect()->route('pharmacy.index', [
            'patient_id' => $outcome->patientId,
            'external_intake' => 1,
        ])->with('status', 'Minimal pharmacy patient record created at this facility.');
    }

    public function store(
        TranscribePaperPrescriptionRequest $request,
        TenantContext $context,
        TranscriptionBatchService $transcriptions,
        InventoryApi $inventoryApi,
        PatientApi $patientApi,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');

        $lines = $request->validated('lines');
        foreach ($lines as $line) {
            $item = $inventoryApi->getItem((string) $line['inventory_item_id']);
            if ($item === null || ! $item->active || $item->itemType !== 'MEDICATION') {
                return back()->withErrors([
                    'prescription' => 'Every prescription line must use an active medicine from the medication catalog.',
                ]);
            }
        }

        // Ensure the supplied patient is registered at the active facility. If
        // not, instruct the pharmacist to create a local pharmacy-only patient
        // record first to preserve per-facility accounting and workflows.
        $patientId = (string) $request->input('patient_id');
        if (! $patientApi->patientExists($patientId) || ! $patientApi->patientIsRegisteredAtFacility($patientId, $facilityId)) {
            return back()->withErrors(['patient' => 'Select or create a patient registered at this facility before transcribing the prescription.']);
        }

        try {
            $transcriptions->submit(
                facilityId: $facilityId,
                lines: $lines,
                patientId: $patientId,
                actorId: (string) $user->getAuthIdentifier(),
                prescriberName: (string) $request->input('prescriber_name'),
                transcriptionNote: $request->filled('transcription_note')
                    ? trim((string) $request->input('transcription_note'))
                    : null,
                externalIntakeId: $request->validated('external_intake_id'),
            );
        } catch (AuthorizationException) {
            return back()->withErrors(['prescription' => 'Not authorized to record the prescription and create its pharmacy order.']);
        } catch (DomainException $e) {
            return back()->withErrors(['prescription' => 'External prescription intake was not completed: '.$e->getMessage()]);
        }

        return redirect()->route('pharmacy.index', [
            'view' => 'orders',
            'order_id' => (string) $lines[0]['pharmacy_order_id'],
            'patient_id' => (string) $request->input('patient_id'),
            'external_intake' => 1,
        ])->with('status', count($lines).' external prescription line(s) recorded and queued for pharmacist validation.');
    }
}
