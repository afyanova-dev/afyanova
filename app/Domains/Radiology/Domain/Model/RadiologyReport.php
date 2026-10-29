<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Domain\Model;

use App\Domains\Radiology\Contracts\Event\RadiologyReportAmended;
use App\Domains\Radiology\Contracts\Event\RadiologyReportCancelled;
use App\Domains\Radiology\Contracts\Event\RadiologyReportCreated;
use App\Domains\Radiology\Contracts\Event\RadiologyReportReleased;
use App\Domains\Radiology\Contracts\Event\RadiologyReportVerified;
use App\Domains\Radiology\Database\Factories\RadiologyReportFactory;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * Radiology report aggregate root — ONE prose document per study
 * (15-radiology-domain.md §40–§45, §103, §184; ADR-167 D-B, ADR-168 D-C,
 * ADR-169 D-D).
 *
 * Emits RadiologyReportCreated (create → DRAFT), RadiologyReportVerified,
 * RadiologyReportReleased, RadiologyReportAmended (Rad.3b), RadiologyReportCancelled
 * (Rad.3b). `UpdateRadiologyReport` / `MarkReportReviewed` / `flagCriticalFinding()`
 * have NO event. Every event is PHI-safe (ADR-136 — report text / amendment
 * reasons never carried).
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
 * @property string $study_id
 * @property string $order_id
 * @property string $order_item_id
 * @property string $patient_id
 * @property string $encounter_id
 * @property string $accession_number
 * @property string $modality_code
 * @property string $service_code
 * @property string|null $clinical_indication_text
 * @property string|null $technique_text
 * @property string|null $findings_text
 * @property string|null $impression_text
 * @property string|null $recommendations_text
 * @property string $status
 * @property string|null $release_hold_reason
 * @property int $amendment_count
 * @property string|null $cancellation_reason
 * @property bool $critical_finding_flagged
 * @property string $authored_by_user_id
 * @property CarbonImmutable|null $authored_at
 * @property string|null $reviewed_by_user_id
 * @property CarbonImmutable|null $reviewed_at
 * @property string|null $verified_by_user_id
 * @property CarbonImmutable|null $verified_at
 * @property string|null $released_by_user_id
 * @property CarbonImmutable|null $released_at
 * @property string $created_by_user_id
 * @property CarbonImmutable|null $occurred_at
 * @property CarbonImmutable|null $recorded_at
 * @property CarbonImmutable|null $updated_at
 * @property int $version
 */
final class RadiologyReport extends AggregateRoot
{
    /** @use HasFactory<RadiologyReportFactory> */
    use HasFactory;

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_REVIEWED = 'REVIEWED';

    public const STATUS_VERIFIED = 'VERIFIED';

    public const STATUS_RELEASED = 'RELEASED';

    /** ADR-168 (Rad.3b). */
    public const STATUS_AMENDED = 'AMENDED';

    /** ADR-168 (Rad.3b). */
    public const STATUS_CANCELLED = 'CANCELLED';

    public const AMENDMENT_CORRECTION = 'CORRECTION';

    public const AMENDMENT_ADDENDUM = 'ADDENDUM';

    protected $table = 'rad_radiology_reports';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id',
        'study_id', 'order_id', 'order_item_id', 'patient_id', 'encounter_id',
        'accession_number', 'modality_code', 'service_code',
        'clinical_indication_text', 'technique_text', 'findings_text', 'impression_text', 'recommendations_text',
        'status', 'release_hold_reason', 'amendment_count', 'cancellation_reason', 'critical_finding_flagged',
        'authored_by_user_id', 'authored_at',
        'reviewed_by_user_id', 'reviewed_at',
        'verified_by_user_id', 'verified_at',
        'released_by_user_id', 'released_at',
        'created_by_user_id', 'occurred_at', 'recorded_at', 'version',
    ];

    protected static function newFactory(): RadiologyReportFactory
    {
        return RadiologyReportFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'critical_finding_flagged' => 'boolean',
            'amendment_count' => 'integer',
            'authored_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    /**
     * @param  array{
     *     id: string, tenantId: string, facilityId: string, studyId: string,
     *     orderId: string, orderItemId: string, patientId: string, encounterId: string,
     *     accessionNumber: string, modalityCode: string, serviceCode: string,
     *     clinicalIndicationText: string|null, techniqueText: string|null,
     *     findingsText: string|null, impressionText: string|null, recommendationsText: string|null,
     *     authoredByUserId: string
     * }  $data
     */
    public static function create(array $data): self
    {
        $now = CarbonImmutable::now();

        $report = new self([
            'id' => $data['id'],
            'tenant_id' => $data['tenantId'],
            'facility_id' => $data['facilityId'],
            'study_id' => $data['studyId'],
            'order_id' => $data['orderId'],
            'order_item_id' => $data['orderItemId'],
            'patient_id' => $data['patientId'],
            'encounter_id' => $data['encounterId'],
            'accession_number' => $data['accessionNumber'],
            'modality_code' => $data['modalityCode'],
            'service_code' => $data['serviceCode'],
            'clinical_indication_text' => $data['clinicalIndicationText'],
            'technique_text' => $data['techniqueText'],
            'findings_text' => $data['findingsText'],
            'impression_text' => $data['impressionText'],
            'recommendations_text' => $data['recommendationsText'],
            'status' => self::STATUS_DRAFT,
            'amendment_count' => 0,
            'critical_finding_flagged' => false,
            'authored_by_user_id' => $data['authoredByUserId'],
            'authored_at' => $now,
            'created_by_user_id' => $data['authoredByUserId'],
            'occurred_at' => $now,
            'recorded_at' => $now,
        ]);

        $report->recordThat(new RadiologyReportCreated(
            eventId: (string) Str::uuid7(),
            tenantId: $data['tenantId'],
            facilityId: $data['facilityId'],
            reportId: $data['id'],
            studyId: $data['studyId'],
            orderId: $data['orderId'],
            patientId: $data['patientId'],
            encounterId: $data['encounterId'],
            accessionNumber: $data['accessionNumber'],
            modalityCode: $data['modalityCode'],
            serviceCode: $data['serviceCode'],
            occurredAt: $now->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));

        return $report;
    }

    /**
     * @param  array{clinicalIndicationText?: string|null, techniqueText?: string|null, findingsText?: string|null, impressionText?: string|null, recommendationsText?: string|null}  $fields
     */
    public function updateContent(array $fields): void
    {
        foreach ([
            'clinicalIndicationText' => 'clinical_indication_text',
            'techniqueText' => 'technique_text',
            'findingsText' => 'findings_text',
            'impressionText' => 'impression_text',
            'recommendationsText' => 'recommendations_text',
        ] as $key => $column) {
            if (array_key_exists($key, $fields)) {
                $value = $fields[$key];
                $this->{$column} = ($value !== null && trim($value) !== '') ? trim($value) : null;
            }
        }
    }

    public function markReviewed(string $reviewedByUserId): void
    {
        $this->status = self::STATUS_REVIEWED;
        $this->reviewed_by_user_id = $reviewedByUserId;
        $this->reviewed_at = CarbonImmutable::now();
    }

    public function verify(string $verifiedByUserId): void
    {
        $now = CarbonImmutable::now();
        $this->status = self::STATUS_VERIFIED;
        $this->verified_by_user_id = $verifiedByUserId;
        $this->verified_at = $now;

        $this->recordThat(new RadiologyReportVerified(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            reportId: (string) $this->id,
            studyId: (string) $this->study_id,
            orderId: (string) $this->order_id,
            patientId: (string) $this->patient_id,
            accessionNumber: (string) $this->accession_number,
            occurredAt: $now->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    public function release(string $releasedByUserId): void
    {
        $now = CarbonImmutable::now();
        $this->status = self::STATUS_RELEASED;
        $this->released_by_user_id = $releasedByUserId;
        $this->released_at = $now;

        $this->recordThat(new RadiologyReportReleased(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            reportId: (string) $this->id,
            studyId: (string) $this->study_id,
            orderId: (string) $this->order_id,
            patientId: (string) $this->patient_id,
            accessionNumber: (string) $this->accession_number,
            occurredAt: $now->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    /**
     * ADR-168 (Rad.3b) — apply a CORRECTION (replace the section text) or an
     * ADDENDUM (append labelled supplementary text). The before/after + reason
     * are recorded on an append-only `rad_report_amendments` row by the handler;
     * here the change is applied and the row dirtied via `amendment_count` (the
     * T.2 lesson — a 2nd amendment re-assigns `status` to the same value).
     *
     * @param  array{findingsText: string|null, impressionText: string|null, recommendationsText: string|null}  $corrected
     */
    public function amend(array $corrected): void
    {
        $this->findings_text = $corrected['findingsText'];
        $this->impression_text = $corrected['impressionText'];
        $this->recommendations_text = $corrected['recommendationsText'];
        $this->status = self::STATUS_AMENDED;
        $this->amendment_count = (int) $this->amendment_count + 1;

        $this->recordThat(new RadiologyReportAmended(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            reportId: (string) $this->id,
            studyId: (string) $this->study_id,
            orderId: (string) $this->order_id,
            patientId: (string) $this->patient_id,
            accessionNumber: (string) $this->accession_number,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    /** ADR-168 (Rad.3b) — invalidate a non-released report (§164). `reason` row-only + audit. */
    public function cancelReport(string $reason): void
    {
        $this->status = self::STATUS_CANCELLED;
        $this->cancellation_reason = trim($reason);
        $this->amendment_count = (int) $this->amendment_count + 1;

        $this->recordThat(new RadiologyReportCancelled(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            reportId: (string) $this->id,
            studyId: (string) $this->study_id,
            orderId: (string) $this->order_id,
            patientId: (string) $this->patient_id,
            accessionNumber: (string) $this->accession_number,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    /**
     * ADR-169 (Rad.3c) — mark that a critical finding has been recorded against
     * this report. Eventless (the `RadiologyCriticalFinding` aggregate emits
     * `CriticalImagingFindingIdentified`); `amendment_count` keeps the row dirty
     * for the optimistic-lock UPDATE.
     */
    public function flagCriticalFinding(): void
    {
        $this->critical_finding_flagged = true;
        $this->amendment_count = (int) $this->amendment_count + 1;
    }
}
