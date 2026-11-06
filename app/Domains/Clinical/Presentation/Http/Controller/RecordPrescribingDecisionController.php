<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Presentation\Http\Controller;

use App\Domains\Clinical\Application\Command\RecordPrescribingDecision;
use App\Domains\Clinical\Domain\ValueObject\MedicationSubstance;
use App\Domains\Clinical\Presentation\Http\Request\RecordPrescribingDecisionRequest;
use App\Models\User;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class RecordPrescribingDecisionController
{
    public function __invoke(
        RecordPrescribingDecisionRequest $request,
        CommandBus $commandBus,
        string $encounter,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $data = $request->validated();

        try {
            $commandBus->dispatch(new RecordPrescribingDecision(
                prescribingDecisionId: (string) Str::uuid7(),
                actorId: (string) $user->getAuthIdentifier(),
                patientId: $data['patient_id'],
                encounterId: $encounter,
                medication: MedicationSubstance::of(
                    $data['medication_name'],
                    $data['medication_code'] ?? null,
                    $data['medication_code_system'] ?? null,
                ),
                dose: $data['dose'] ?? null,
                route: $data['route'] ?? null,
                frequency: $data['frequency'] ?? null,
                duration: $data['duration'] ?? null,
                quantity: $data['quantity'] ?? null,
                quantityValue: isset($data['quantity_value']) ? (string) $data['quantity_value'] : null,
                quantityUnit: isset($data['quantity_value']) ? ($data['quantity_unit'] ?? null) : null,
                dosingInstruction: $data['dosing_instruction'] ?? null,
                therapeuticIntent: $data['therapeutic_intent'] ?? null,
                indication: $data['indication'] ?? null,
                urgency: $data['urgency'] ?? null,
                clinicalPlanId: $data['clinical_plan_id'] ?? null,
                indicationDiagnosisId: $data['indication_diagnosis_id'] ?? null,
                indicationProblemId: $data['indication_problem_id'] ?? null,
                occurredAt: $data['occurred_at'] ?? null,
                reason: $data['reason'] ?? null,
                idempotencyKey: $data['idempotency_key'],
            ));
        } catch (DomainException|InvalidArgumentException $exception) {
            return back()->withErrors(['medication_error' => $exception->getMessage()]);
        }

        return back()->with('status', 'Medication prescribing decision recorded.');
    }
}
