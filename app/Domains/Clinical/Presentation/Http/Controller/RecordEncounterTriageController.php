<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Presentation\Http\Controller;

use App\Domains\Clinical\Application\Command\RecordTriageAssessment;
use App\Domains\Clinical\Presentation\Http\Request\RecordEncounterTriageRequest;
use App\Models\User;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\DomainException;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class RecordEncounterTriageController
{
    public function __invoke(
        RecordEncounterTriageRequest $request,
        CommandBus $commandBus,
        TenantContext $context,
        NursingQueueAssessmentGuard $queueGuard,
        string $encounter,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $data = $request->validated();
        $unavailableReason = $queueGuard->unavailableReason($context, $data['patient_id'], $encounter);
        if ($unavailableReason !== null) {
            return back()->withErrors(['triage_error' => $unavailableReason]);
        }

        try {
            $commandBus->dispatch(new RecordTriageAssessment(
                triageAssessmentId: (string) Str::uuid7(),
                actorId: (string) $user->getAuthIdentifier(),
                patientId: $data['patient_id'],
                encounterId: $encounter,
                chiefComplaint: $data['chief_complaint'],
                triagePriority: $data['triage_priority'],
                acuity: $data['acuity'] ?? null,
                consciousness: $data['consciousness'] ?? null,
                sourceVitalsObservationId: $data['source_vitals_observation_id'] ?? null,
                authoredContext: 'NURSING',
                occurredAt: $data['occurred_at'] ?? null,
                reason: $data['reason'] ?? null,
            ));
        } catch (DomainException $exception) {
            return back()->withErrors(['triage_error' => $exception->getMessage()]);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['occurred_at' => $exception->getMessage()]);
        }

        return back();
    }
}
