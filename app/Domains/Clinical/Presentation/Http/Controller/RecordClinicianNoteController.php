<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Presentation\Http\Controller;

use App\Domains\Clinical\Application\ClinicalDocumentationDraftService;
use App\Domains\Clinical\Application\Command\CreateClinicalNote;
use App\Domains\Clinical\Presentation\Http\Request\RecordClinicianNoteRequest;
use App\Models\User;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\DomainException;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

final class RecordClinicianNoteController
{
    public function __invoke(
        RecordClinicianNoteRequest $request,
        CommandBus $commandBus,
        TenantContext $context,
        ClinicalDocumentationDraftService $drafts,
        string $encounter,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $data = $request->validated();

        try {
            $commandBus->dispatch(new CreateClinicalNote(
                clinicalNoteId: (string) Str::uuid7(),
                actorId: (string) $user->getAuthIdentifier(),
                patientId: $data['patient_id'],
                encounterId: $encounter,
                noteType: $data['note_type'],
                title: $data['title'] ?? null,
                body: $data['body'],
                authoredContext: 'CLINICIAN',
            ));
        } catch (DomainException $exception) {
            return back()->withErrors(['note_error' => $exception->getMessage()]);
        }

        $facilityId = $context->facilityId();
        if ($facilityId !== null && $facilityId !== '') {
            $drafts->delete(
                (string) $context->tenantId(),
                $facilityId,
                (string) $user->getAuthIdentifier(),
                $encounter,
                'NOTE',
            );
        }

        return back()->with('status', 'Clinical note saved as a draft.');
    }
}
