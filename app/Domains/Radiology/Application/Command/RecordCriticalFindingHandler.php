<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Command;

use App\Domains\Radiology\Domain\Model\RadiologyCriticalFinding;
use App\Domains\Radiology\Domain\RadiologyCriticalFindingRepository;
use App\Domains\Radiology\Domain\RadiologyReportRepository;
use App\Domains\Radiology\Domain\Service\RadiologyReportLifecycle;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Orchestrates `RecordCriticalFinding` (ADR-169 D-E).
 * `RadiologyCriticalFindingRepository` is injected FIRST so the bus resolves
 * `RadiologyCriticalFindingPolicy`.
 *
 * Flow: load the report -> ADR-133 per-report-facility refinement -> the report
 * must be VERIFIED | RELEASED | AMENDED -> create the critical finding
 * (snapshotting study / order / patient / accession / modality) -> flag the
 * report (`getByIdForUpdate` — eventless, dirties via `amendment_count`) ->
 * save both (one transaction, two aggregates — the Lab.3c precedent).
 */
final class RecordCriticalFindingHandler
{
    public function __construct(
        private readonly RadiologyCriticalFindingRepository $findings,
        private readonly RadiologyReportRepository $reports,
        private readonly RadiologyReportLifecycle $reportLifecycle,
        private readonly ScopeChecker $scope,
    ) {}

    public function __invoke(RecordCriticalFinding $command): RadiologyCriticalFinding
    {
        $report = $this->reports->getByIdForUpdate($command->reportId);

        $this->assertActorScopedToReportFacility((string) $report->facility_id, $command->criticalFindingId);

        $this->reportLifecycle->assertCanFlagCriticalFinding((string) $report->status);

        $finding = RadiologyCriticalFinding::identify([
            'id' => $command->criticalFindingId,
            'tenantId' => (string) $report->tenant_id,
            'facilityId' => (string) $report->facility_id,
            'reportId' => (string) $report->id,
            'studyId' => (string) $report->study_id,
            'orderId' => (string) $report->order_id,
            'patientId' => (string) $report->patient_id,
            'accessionNumber' => (string) $report->accession_number,
            'modalityCode' => (string) $report->modality_code,
            'findingSummary' => trim($command->findingSummary),
            'severity' => strtoupper(trim($command->severity)),
            'identifiedByUserId' => $command->actorId,
        ]);

        $this->findings->save($finding);

        $report->flagCriticalFinding();
        $this->reports->save($report);

        return $finding;
    }

    private function assertActorScopedToReportFacility(string $facilityId, string $criticalFindingId): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new RuntimeException('RecordCriticalFinding dispatched without an authenticated user (IC §5).');
        }

        if (! in_array('radiology.critical.manage', $this->scope->effectivePermissions($user, $facilityId), true)) {
            throw NotFound::aggregate(RadiologyCriticalFinding::class, $criticalFindingId);
        }
    }
}
