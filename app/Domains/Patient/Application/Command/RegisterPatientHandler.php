<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application\Command;

use App\Domains\Patient\Contracts\Event\PatientDuplicateDetected;
use App\Domains\Patient\Domain\Enum\DuplicateOutcome;
use App\Domains\Patient\Domain\Exception\PatientRegistrationBlocked;
use App\Domains\Patient\Domain\Model\Patient;
use App\Domains\Patient\Domain\Model\PatientRegistration;
use App\Domains\Patient\Domain\PatientRegistrationRepository;
use App\Domains\Patient\Domain\PatientRepository;
use App\Domains\Patient\Domain\Service\DuplicateAssessment;
use App\Domains\Patient\Domain\Service\DuplicateSignals;
use App\Domains\Patient\Domain\Service\PatientDuplicateDetector;
use App\Domains\Patient\Infrastructure\Sequence\PatientNumberSequence;
use App\Shared\Audit\AuditLogger;
use App\Shared\Events\OutboxWriter;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Str;

final class RegisterPatientHandler
{
    public function __construct(
        private readonly PatientRepository $patients,
        private readonly PatientRegistrationRepository $registrations,
        private readonly PatientNumberSequence $numbers,
        private readonly TenantContext $context,
        private readonly PatientDuplicateDetector $detector,
        private readonly OutboxWriter $outbox,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(RegisterPatient $command): RegisterPatientResult|RegisterPatientReview
    {
        $tenantId = $this->context->tenantId();
        $facilityId = $this->context->requireFacilityId();

        $assessment = $this->detector->assess(DuplicateSignals::fromRegistration(
            givenName: $command->givenName,
            familyName: $command->familyName,
            dateOfBirth: $command->dateOfBirth,
            sex: $command->sex,
            primaryPhone: $command->primaryPhone,
            nationalId: $command->nationalId,
            insuranceMemberNumber: $command->insuranceMemberNumber,
        ));

        $overriding = $command->overrideDuplicate && $assessment->outcome !== DuplicateOutcome::Allow;

        if (! $overriding && $assessment->outcome === DuplicateOutcome::Block) {
            $topCandidate = $assessment->matched[0] ?? null;
            $collidingField = null;
            $candidateSummary = $topCandidate !== null
                ? "{$topCandidate->displayName} ({$topCandidate->patientNumber})"
                : null;
            $message = 'Registration blocked: a matching patient identity already exists in this tenant.';

            if ($topCandidate !== null) {
                if ($topCandidate->hasVerifiedIdentifierMatch) {
                    $collidingField = $command->nationalId !== null ? 'national_id' : 'insurance_id';
                    $val = $command->nationalId ?? $command->insuranceMemberNumber ?? '';
                    $message = "Registration blocked: official identifier [{$val}] is already registered to patient {$candidateSummary}.";
                } elseif ($command->primaryPhone !== null && preg_replace('/\D+/', '', $command->primaryPhone) === $topCandidate->normalizedPhone) {
                    $collidingField = 'primary_phone';
                    $message = "Registration blocked: phone number [{$command->primaryPhone}] is already registered to patient {$candidateSummary}.";
                } elseif (
                    $command->dateOfBirth !== null
                    && $command->dateOfBirth === $topCandidate->dateOfBirth
                    && str_replace(' ', '', strtoupper($command->familyName)) === str_replace(' ', '', $topCandidate->normalizedFamilyName)
                ) {
                    $collidingField = 'date_of_birth';
                    $message = "Registration blocked: existing patient {$candidateSummary} has matching family name and birth date [{$command->dateOfBirth}].";
                } else {
                    $message = "Registration blocked: high-confidence match with existing patient {$candidateSummary}.";
                }
            }

            throw new PatientRegistrationBlocked(
                candidatePatientIds: $assessment->matchedPatientIds(),
                confidenceBand: $assessment->confidenceBand,
                message: $message,
                collidingField: $collidingField,
                candidateSummary: $candidateSummary,
                candidates: $this->candidateViews($assessment),
            );
        }

        if (! $overriding && $assessment->outcome === DuplicateOutcome::Review) {
            $this->outbox->write(new PatientDuplicateDetected(
                eventId: (string) Str::uuid7(),
                tenantId: $tenantId,
                attemptedPatientId: $command->patientId,
                attemptedByUserId: $command->actorId,
                candidatePatientIds: $assessment->matchedPatientIds(),
                confidenceBand: $assessment->confidenceBand,
                occurredAt: (string) now()->toISOString(),
                correlationId: CorrelationId::get(),
                causationId: Causation::get(),
            ));

            return new RegisterPatientReview($this->candidateViews($assessment), $assessment->confidenceBand);
        }

        $patientNumber = $this->numbers->next($tenantId);

        $patient = Patient::register(
            id: $command->patientId,
            tenantId: $tenantId,
            patientNumber: $patientNumber,
            facilityId: $facilityId,
            givenName: $command->givenName,
            familyName: $command->familyName,
            middleName: $command->middleName,
            displayName: $command->displayName ?? trim("{$command->givenName} {$command->familyName}"),
            sex: $command->sex,
            dateOfBirth: $command->dateOfBirth,
            dobPrecision: $command->dobPrecision,
            primaryPhone: $command->primaryPhone,
            email: $command->email,
            provisional: $command->provisional,
            source: $command->source,
            registeredByUserId: $command->actorId,
            photoDocumentId: $command->photoDocumentId,
            photoCapturedBy: $command->photoDocumentId !== null ? $command->actorId : null,
        );

        $this->patients->save($patient); // persists + writes PatientRegistered to the outbox (IC §7.2)
        $this->registrations->save(PatientRegistration::register(
            tenantId: $tenantId,
            facilityId: $facilityId,
            patientId: $command->patientId,
        ));

        $this->audit->record(
            action: 'patient.registered',
            actorId: $command->actorId,
            targetType: 'patient',
            targetId: (string) $patient->id,
            reason: $overriding ? $command->overrideReason : null,
            metadata: $this->auditMetadata($command, $overriding ? $assessment : null),
        );

        return new RegisterPatientResult((string) $patient->id, $patientNumber);
    }

    /**
     * @return list<DuplicateCandidateView>
     */
    private function candidateViews(DuplicateAssessment $assessment): array
    {
        return array_map(
            static fn ($candidate): DuplicateCandidateView => new DuplicateCandidateView(
                patientId: $candidate->patientId,
                patientNumber: $candidate->patientNumber,
                displayName: $candidate->displayName,
                dateOfBirth: $candidate->dateOfBirth,
            ),
            $assessment->matched,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function auditMetadata(RegisterPatient $command, ?DuplicateAssessment $overridden): array
    {
        $metadata = [
            'provisional' => $command->provisional,
            'source' => $command->source,
        ];

        if ($overridden !== null) {
            $metadata['duplicate_override'] = true;
            $metadata['overridden_outcome'] = $overridden->outcome->value;
            $metadata['matched_patient_ids'] = $overridden->matchedPatientIds();
        }

        return $metadata;
    }
}
