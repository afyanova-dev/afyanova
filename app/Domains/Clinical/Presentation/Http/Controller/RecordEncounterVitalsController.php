<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Presentation\Http\Controller;

use App\Domains\Clinical\Application\Command\RecordVitalSigns;
use App\Domains\Clinical\Domain\ValueObject\VitalSigns;
use App\Domains\Clinical\Presentation\Http\Request\RecordEncounterVitalsRequest;
use App\Models\User;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\DomainException;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class RecordEncounterVitalsController
{
    public function __invoke(
        RecordEncounterVitalsRequest $request,
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
            return back()->withErrors(['vitals_error' => $unavailableReason]);
        }

        $measurements = VitalSigns::of(
            temperature: $this->floatOrNull($data['temperature'] ?? null),
            heartRate: $this->intOrNull($data['heart_rate'] ?? null),
            respiratoryRate: $this->intOrNull($data['respiratory_rate'] ?? null),
            systolicBp: $this->intOrNull($data['systolic_bp'] ?? null),
            diastolicBp: $this->intOrNull($data['diastolic_bp'] ?? null),
            oxygenSaturation: $this->intOrNull($data['oxygen_saturation'] ?? null),
            weight: $this->floatOrNull($data['weight'] ?? null),
            height: $this->floatOrNull($data['height'] ?? null),
            painScore: $this->intOrNull($data['pain_score'] ?? null),
        );

        try {
            $commandBus->dispatch(new RecordVitalSigns(
                vitalSignsObservationId: (string) Str::uuid7(),
                actorId: (string) $user->getAuthIdentifier(),
                patientId: $data['patient_id'],
                encounterId: $encounter,
                measurements: $measurements,
                authoredContext: 'NURSING',
                occurredAt: $data['occurred_at'] ?? null,
                reason: $data['reason'] ?? null,
            ));
        } catch (DomainException $exception) {
            return back()->withErrors(['vitals_error' => $exception->getMessage()]);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['occurred_at' => $exception->getMessage()]);
        }

        return back()->with('status', 'Vital signs recorded.');
    }

    private function floatOrNull(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }

    private function intOrNull(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
