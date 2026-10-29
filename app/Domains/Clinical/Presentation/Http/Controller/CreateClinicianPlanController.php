<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Presentation\Http\Controller;

use App\Domains\Clinical\Application\Command\CreateClinicalPlan;
use App\Domains\Clinical\Presentation\Http\Request\CreateClinicianPlanRequest;
use App\Models\User;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

final class CreateClinicianPlanController
{
    public function __invoke(CreateClinicianPlanRequest $request, CommandBus $commandBus, string $encounter): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $data = $request->validated();

        try {
            $commandBus->dispatch(new CreateClinicalPlan(
                clinicalPlanId: (string) Str::uuid7(),
                actorId: (string) $user->getAuthIdentifier(),
                patientId: $data['patient_id'],
                encounterId: $encounter,
                planType: $data['plan_type'],
                clinicalIntent: $data['clinical_intent'],
                goals: $data['goals'] ?? null,
                plannedActions: $data['planned_actions'] ?? null,
                instructions: $data['instructions'] ?? null,
                followUp: $data['follow_up'] ?? null,
            ));
        } catch (DomainException $exception) {
            return back()->withErrors(['plan_error' => $exception->getMessage()]);
        }

        return back()->with('status', 'Clinical plan recorded.');
    }
}
