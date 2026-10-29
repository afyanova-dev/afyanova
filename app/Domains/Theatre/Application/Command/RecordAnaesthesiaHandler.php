<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Domains\Theatre\Domain\AnaesthesiaRecordRepository;
use App\Domains\Theatre\Domain\Exception\AnaesthesiaRecordAlreadyExistsForCase;
use App\Domains\Theatre\Domain\Model\AnaesthesiaRecord;
use App\Domains\Theatre\Domain\Service\AnaesthesiaRecordLifecycle;
use App\Domains\Theatre\Domain\SurgicalCaseRepository;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Orchestrates `RecordAnaesthesia` (ADR-155 D-C/D-I). `AnaesthesiaRecordRepository`
 * is injected FIRST so the bus resolves `AnaesthesiaRecordPolicy` (IC §5.1).
 * The SurgicalCase is loaded via Theatre's own repository (the T.2 precedent)
 * for the eligibility gate + the denormalised context copy + the ADR-133
 * per-case-facility refinement.
 */
final class RecordAnaesthesiaHandler
{
    public function __construct(
        private readonly AnaesthesiaRecordRepository $records,
        private readonly SurgicalCaseRepository $cases,
        private readonly AnaesthesiaRecordLifecycle $lifecycle,
        private readonly ScopeChecker $scope,
    ) {}

    public function __invoke(RecordAnaesthesia $command): AnaesthesiaRecord
    {
        $case = $this->cases->getById($command->caseId);

        $this->assertActorScopedToCaseFacility((string) $case->facility_id, $command->recordId);

        $this->lifecycle->assertCaseEligibleForRecord((string) $case->id, (string) $case->status);

        $record = AnaesthesiaRecord::create(
            id: $command->recordId,
            tenantId: (string) $case->tenant_id,
            facilityId: (string) $case->facility_id,
            caseId: (string) $case->id,
            patientId: (string) $case->patient_id,
            encounterId: (string) $case->encounter_id,
            anaesthesiaType: strtoupper(trim($command->anaesthesiaType)),
            providerUserId: $command->providerUserId,
            startedAt: $command->startedAt !== null ? CarbonImmutable::parse($command->startedAt) : null,
            endedAt: $command->endedAt !== null ? CarbonImmutable::parse($command->endedAt) : null,
            content: $command->content,
            outcome: $command->outcome !== null ? trim($command->outcome) : null,
            createdByUserId: $command->actorId,
        );

        try {
            $this->records->save($record);
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'thr_anaesthesia_records_tenant_id_case_id_unique')) {
                throw AnaesthesiaRecordAlreadyExistsForCase::forCase((string) $case->id);
            }

            throw $e;
        }

        return $record;
    }

    private function assertActorScopedToCaseFacility(string $facilityId, string $recordId): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new RuntimeException('RecordAnaesthesia dispatched without an authenticated user (IC §5).');
        }

        if (! in_array(
            'theatre.anaesthesia.record',
            $this->scope->effectivePermissions($user, $facilityId),
            true,
        )) {
            throw NotFound::aggregate(AnaesthesiaRecord::class, $recordId);
        }
    }
}
