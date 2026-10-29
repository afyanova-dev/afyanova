<?php

declare(strict_types=1);

namespace App\Domains\Patient\Presentation\Http\Controller;

use App\Domains\Patient\Application\Command\ArchivePatientStickyNote;
use App\Domains\Patient\Application\Command\CreatePatientStickyNote;
use App\Domains\Patient\Application\Command\UpdatePatientStickyNote;
use App\Domains\Patient\Domain\Exception\PatientStickyNoteAlreadyExists;
use App\Domains\Patient\Domain\PatientRepository;
use App\Domains\Patient\Presentation\Http\Presenter\PatientStickyNotePresenter;
use App\Models\User;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\NotFound;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class PatientStickyNoteController
{
    public function show(
        string $patientId,
        Request $request,
        PatientRepository $patients,
        PatientStickyNotePresenter $presenter,
    ): JsonResponse {
        $this->authorizedUser($request, 'patient.patients.read');
        $this->patient($patients, $patientId);

        return response()->json(['stickyNote' => $presenter->forPatient($patientId)]);
    }

    public function store(
        string $patientId,
        Request $request,
        PatientRepository $patients,
        CommandBus $commands,
        PatientStickyNotePresenter $presenter,
    ): JsonResponse {
        $user = $this->authorizedUser($request, 'patient.patients.update');
        $this->patient($patients, $patientId);
        $validated = $request->validate(['body' => ['required', 'string', 'max:300']]);

        try {
            $commands->dispatch(new CreatePatientStickyNote(
                stickyNoteId: (string) Str::uuid7(),
                patientId: $patientId,
                actorId: (string) $user->id,
                body: $validated['body'],
                idempotencyKey: $request->header('Idempotency-Key'),
            ));
        } catch (PatientStickyNoteAlreadyExists $exception) {
            return response()->json(['error' => $exception->getMessage()], 409);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }

        return response()->json(['stickyNote' => $presenter->forPatient($patientId)], 201);
    }

    public function update(
        string $patientId,
        string $stickyNoteId,
        Request $request,
        PatientStickyNotePresenter $presenter,
        CommandBus $commands,
    ): JsonResponse {
        $user = $this->authorizedUser($request, 'patient.patients.update');
        $presenter->assertBelongsToPatient($patientId, $stickyNoteId);
        $validated = $request->validate(['body' => ['required', 'string', 'max:300']]);

        try {
            $commands->dispatch(new UpdatePatientStickyNote(
                stickyNoteId: $stickyNoteId,
                actorId: (string) $user->id,
                body: $validated['body'],
                idempotencyKey: $request->header('Idempotency-Key'),
            ));
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }

        return response()->json(['stickyNote' => $presenter->byId($stickyNoteId)]);
    }

    public function destroy(
        string $patientId,
        string $stickyNoteId,
        Request $request,
        PatientStickyNotePresenter $presenter,
        CommandBus $commands,
    ): JsonResponse {
        $user = $this->authorizedUser($request, 'patient.patients.update');
        $presenter->assertBelongsToPatient($patientId, $stickyNoteId);

        $commands->dispatch(new ArchivePatientStickyNote(
            stickyNoteId: $stickyNoteId,
            actorId: (string) $user->id,
            idempotencyKey: $request->header('Idempotency-Key'),
        ));

        return response()->json(['archived' => true]);
    }

    private function authorizedUser(Request $request, string $permission): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($user->can($permission), 403, 'Unauthorized.');

        return $user;
    }

    private function patient(PatientRepository $patients, string $patientId): void
    {
        try {
            $patients->getById($patientId);
        } catch (NotFound) {
            abort(404, 'Patient not found.');
        }
    }
}
