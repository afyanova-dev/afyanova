<?php

declare(strict_types=1);

namespace App\Domains\Patient\Presentation\Http\Controller;

use App\Domains\Patient\Application\Command\UpdatePatient;
use App\Domains\Patient\Domain\Exception\MergedPatientIsImmutable;
use App\Models\User;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class PatientUpdateController
{
    public function __invoke(
        string $patientId,
        Request $request,
        TenantContext $context,
        CommandBus $commandBus,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        abort_unless($user->can('patient.patients.update'), 403, 'Unauthorized.');

        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');

        $validated = $request->validate([
            'given_name' => ['sometimes', 'required', 'string', 'max:100'],
            'family_name' => ['sometimes', 'required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'sex' => ['sometimes', 'required', 'string', 'in:MALE,FEMALE,UNKNOWN'],
            'dob_precision' => ['sometimes', 'required', 'string', 'in:KNOWN,ESTIMATED,UNKNOWN'],
            'date_of_birth' => ['nullable', 'date_format:Y-m-d'],
            'primary_phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $dobPrecision = $validated['dob_precision'] ?? null;
        if ($dobPrecision === 'KNOWN' && empty($validated['date_of_birth'])) {
            throw ValidationException::withMessages([
                'date_of_birth' => ['Date of birth is required when DOB precision is Known.'],
            ]);
        }

        // Build changes map
        $changes = [];
        foreach (UpdatePatient::EDITABLE as $field) {
            if (array_key_exists($field, $validated)) {
                $changes[$field] = $validated[$field];
            }
        }

        if ($changes === []) {
            return redirect()->back()->with('info', 'No changes detected.');
        }

        $reason = isset($validated['reason']) ? trim((string) $validated['reason']) : null;

        // ADR-127 Rule 5: sensitive demographic changes require a reason
        $touchesSensitive = array_intersect(array_keys($changes), UpdatePatient::SENSITIVE) !== [];
        if ($touchesSensitive && ($reason === null || $reason === '')) {
            throw ValidationException::withMessages([
                'reason' => ['A clinical or administrative reason is required when modifying identity demographics (ADR-127).'],
            ]);
        }

        try {
            $commandBus->dispatch(new UpdatePatient(
                patientId: $patientId,
                actorId: (string) $user->id,
                changes: $changes,
                reason: $reason,
                idempotencyKey: $request->header('Idempotency-Key'),
            ));
        } catch (MergedPatientIsImmutable) {
            return redirect()->back()->withErrors([
                'error' => 'This patient record has been merged and is immutable (ADR-128).',
            ]);
        } catch (NotFound) {
            abort(404, 'Patient not found.');
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'error' => [$e->getMessage()],
            ]);
        }

        return redirect()->back()->with('success', 'Patient demographics updated successfully.');
    }
}
