<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Command;

use App\Domains\Radiology\Domain\Exception\RadiologyReportAlreadyExists;
use App\Domains\Radiology\Domain\Exception\StudyNotReportable;
use App\Domains\Radiology\Domain\ImagingOrderRepository;
use App\Domains\Radiology\Domain\ImagingStudyRepository;
use App\Domains\Radiology\Domain\Model\ImagingOrder;
use App\Domains\Radiology\Domain\Model\ImagingStudy;
use App\Domains\Radiology\Domain\Model\RadiologyReport;
use App\Domains\Radiology\Domain\RadiologyReportRepository;
use App\Domains\Radiology\Domain\Service\ImagingOrderLifecycle;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Orchestrates `CreateRadiologyReport` (ADR-167 D-D). `RadiologyReportRepository`
 * is injected FIRST so the bus resolves `RadiologyReportPolicy` (IC §5.1).
 *
 * Flow: load the study (must be COMPLETED — `StudyNotReportable`) -> ADR-133
 * per-study-facility authorization refinement -> one report per study
 * (`RadiologyReportAlreadyExists`) -> snapshot patient / encounter / accession
 * / modality / service -> create the report in DRAFT -> save -> roll the order
 * COMPLETED -> REPORTED (the pre-reserved report-side rollup, ADR-167 D-E).
 */
final class CreateRadiologyReportHandler
{
    public function __construct(
        private readonly RadiologyReportRepository $reports,
        private readonly ImagingStudyRepository $studies,
        private readonly ImagingOrderRepository $orders,
        private readonly ImagingOrderLifecycle $orderLifecycle,
        private readonly ScopeChecker $scope,
    ) {}

    public function __invoke(CreateRadiologyReport $command): RadiologyReport
    {
        $study = $this->studies->getById($command->studyId);

        $this->assertActorScopedToStudyFacility((string) $study->facility_id, $command->reportId);

        if ((string) $study->status !== ImagingStudy::STATUS_COMPLETED) {
            throw StudyNotReportable::status($command->studyId, (string) $study->status);
        }

        if ($this->reports->findByStudy($command->studyId) !== null) {
            throw RadiologyReportAlreadyExists::forStudy($command->studyId);
        }

        $report = RadiologyReport::create([
            'id' => $command->reportId,
            'tenantId' => (string) $study->tenant_id,
            'facilityId' => (string) $study->facility_id,
            'studyId' => (string) $study->id,
            'orderId' => (string) $study->order_id,
            'orderItemId' => (string) $study->order_item_id,
            'patientId' => (string) $study->patient_id,
            'encounterId' => (string) $study->encounter_id,
            'accessionNumber' => (string) $study->accession_number,
            'modalityCode' => (string) $study->modality_code,
            'serviceCode' => (string) $study->service_code,
            'clinicalIndicationText' => self::trimToNull($command->clinicalIndicationText),
            'techniqueText' => self::trimToNull($command->techniqueText),
            'findingsText' => self::trimToNull($command->findingsText),
            'impressionText' => self::trimToNull($command->impressionText),
            'recommendationsText' => self::trimToNull($command->recommendationsText),
            'authoredByUserId' => $command->actorId,
        ]);

        $this->reports->save($report);

        $order = $this->orders->getByIdForUpdate((string) $study->order_id);

        if ((string) $order->status === ImagingOrder::STATUS_COMPLETED) {
            $this->orderLifecycle->assertCanMarkReported((string) $order->status);
            $order->markReported();
            $this->orders->save($order);
        }

        return $report;
    }

    private function assertActorScopedToStudyFacility(string $facilityId, string $reportId): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new RuntimeException('CreateRadiologyReport dispatched without an authenticated user (IC §5).');
        }

        if (! in_array('radiology.reports.create', $this->scope->effectivePermissions($user, $facilityId), true)) {
            throw NotFound::aggregate(RadiologyReport::class, $reportId);
        }
    }

    private static function trimToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
