<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Domain\Model;

use App\Domains\Radiology\Contracts\Event\CriticalImagingFindingAcknowledged;
use App\Domains\Radiology\Contracts\Event\CriticalImagingFindingIdentified;
use App\Domains\Radiology\Database\Factories\RadiologyCriticalFindingFactory;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * Radiology critical finding aggregate root — one critical observation on a
 * radiology report (15-radiology-domain.md §46–§48, §101, §167, §186;
 * ADR-169 D-B). Multiple per report.
 *
 * Emits CriticalImagingFindingIdentified (identify → INITIATED),
 * CriticalImagingFindingAcknowledged (INITIATED → ACKNOWLEDGED). PHI-safe
 * (ADR-136 — severity coded; finding_summary / notes never carried).
 *
 * @domain Radiology
 *
 * @authority authoritative
 *
 * @tenant required
 *
 * @facility required
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $facility_id
 * @property string $report_id
 * @property string $study_id
 * @property string $order_id
 * @property string $patient_id
 * @property string $accession_number
 * @property string $modality_code
 * @property string $finding_summary
 * @property string $severity
 * @property string $status
 * @property string $identified_by_user_id
 * @property CarbonImmutable|null $identified_at
 * @property string|null $acknowledged_by_user_id
 * @property CarbonImmutable|null $acknowledged_at
 * @property string|null $acknowledgement_note
 * @property string $created_by_user_id
 * @property CarbonImmutable|null $occurred_at
 * @property CarbonImmutable|null $recorded_at
 * @property CarbonImmutable|null $updated_at
 * @property int $version
 */
final class RadiologyCriticalFinding extends AggregateRoot
{
    /** @use HasFactory<RadiologyCriticalFindingFactory> */
    use HasFactory;

    public const STATUS_INITIATED = 'INITIATED';

    public const STATUS_ACKNOWLEDGED = 'ACKNOWLEDGED';

    protected $table = 'rad_critical_findings';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id',
        'report_id', 'study_id', 'order_id', 'patient_id', 'accession_number', 'modality_code',
        'finding_summary', 'severity', 'status',
        'identified_by_user_id', 'identified_at',
        'acknowledged_by_user_id', 'acknowledged_at', 'acknowledgement_note',
        'created_by_user_id', 'occurred_at', 'recorded_at', 'version',
    ];

    protected static function newFactory(): RadiologyCriticalFindingFactory
    {
        return RadiologyCriticalFindingFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'identified_at' => 'immutable_datetime',
            'acknowledged_at' => 'immutable_datetime',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    /**
     * @param  array{
     *     id: string, tenantId: string, facilityId: string, reportId: string,
     *     studyId: string, orderId: string, patientId: string, accessionNumber: string,
     *     modalityCode: string, findingSummary: string, severity: string,
     *     identifiedByUserId: string
     * }  $data
     */
    public static function identify(array $data): self
    {
        $now = CarbonImmutable::now();

        $finding = new self([
            'id' => $data['id'],
            'tenant_id' => $data['tenantId'],
            'facility_id' => $data['facilityId'],
            'report_id' => $data['reportId'],
            'study_id' => $data['studyId'],
            'order_id' => $data['orderId'],
            'patient_id' => $data['patientId'],
            'accession_number' => $data['accessionNumber'],
            'modality_code' => $data['modalityCode'],
            'finding_summary' => $data['findingSummary'],
            'severity' => $data['severity'],
            'status' => self::STATUS_INITIATED,
            'identified_by_user_id' => $data['identifiedByUserId'],
            'identified_at' => $now,
            'created_by_user_id' => $data['identifiedByUserId'],
            'occurred_at' => $now,
            'recorded_at' => $now,
        ]);

        $finding->recordThat(new CriticalImagingFindingIdentified(
            eventId: (string) Str::uuid7(),
            tenantId: $data['tenantId'],
            facilityId: $data['facilityId'],
            criticalFindingId: $data['id'],
            reportId: $data['reportId'],
            studyId: $data['studyId'],
            orderId: $data['orderId'],
            patientId: $data['patientId'],
            accessionNumber: $data['accessionNumber'],
            severity: $data['severity'],
            occurredAt: $now->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));

        return $finding;
    }

    public function acknowledge(string $acknowledgedByUserId, ?string $note): void
    {
        $now = CarbonImmutable::now();
        $this->status = self::STATUS_ACKNOWLEDGED;
        $this->acknowledged_by_user_id = $acknowledgedByUserId;
        $this->acknowledged_at = $now;
        $this->acknowledgement_note = ($note !== null && trim($note) !== '') ? trim($note) : null;

        $this->recordThat(new CriticalImagingFindingAcknowledged(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            criticalFindingId: (string) $this->id,
            reportId: (string) $this->report_id,
            studyId: (string) $this->study_id,
            orderId: (string) $this->order_id,
            patientId: (string) $this->patient_id,
            occurredAt: $now->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }
}
