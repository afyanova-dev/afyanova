<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Presentation\Http\Controller;

use App\Domains\Clinical\Application\ClinicalDocumentationDraftService;
use App\Domains\Clinical\Application\Command\RecordDiagnosis;
use App\Domains\Clinical\Domain\ValueObject\DiagnosisCode;
use App\Domains\Clinical\Presentation\Http\Request\RecordClinicianDiagnosisRequest;
use App\Models\User;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\DomainException;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

final class RecordClinicianDiagnosisController
{
    public function __invoke(
        RecordClinicianDiagnosisRequest $request,
        CommandBus $commandBus,
        TenantContext $context,
        ClinicalDocumentationDraftService $drafts,
        string $encounter,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $data = $request->validated();

        try {
            $commandBus->dispatch(new RecordDiagnosis(
                diagnosisId: (string) Str::uuid7(),
                actorId: (string) $user->getAuthIdentifier(),
                patientId: $data['patient_id'],
                encounterId: $encounter,
                coding: DiagnosisCode::of($data['code'] ?? null, $data['code_system'] ?? null),
                description: $data['description'],
                clinicalStatus: $data['clinical_status'] ?? 'WORKING',
            ));
        } catch (DomainException $exception) {
            return back()->withErrors(['diagnosis_error' => $exception->getMessage()]);
        }

        $facilityId = $context->facilityId();
        if ($facilityId !== null && $facilityId !== '') {
            $drafts->delete(
                (string) $context->tenantId(),
                $facilityId,
                (string) $user->getAuthIdentifier(),
                $encounter,
                'DIAGNOSIS',
            );
        }

        return back()->with('status', 'Encounter diagnosis recorded.');
    }
}
