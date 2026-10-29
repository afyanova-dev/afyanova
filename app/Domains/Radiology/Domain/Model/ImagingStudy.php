<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Domain\Model;

use App\Domains\Radiology\Contracts\Event\ImagingStudyAborted;
use App\Domains\Radiology\Contracts\Event\ImagingStudyCancelled;
use App\Domains\Radiology\Contracts\Event\ImagingStudyCompleted;
use App\Domains\Radiology\Contracts\Event\ImagingStudyScheduled;
use App\Domains\Radiology\Contracts\Event\ImagingStudyStarted;
use App\Domains\Radiology\Database\Factories\ImagingStudyFactory;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * Imaging study aggregate root (15-radiology-domain.md §27–§30, §53–§60,
 * §104–§108, §183; ADR-166 D-B/D-D).
 *
 * Emits ImagingStudyScheduled, ImagingStudyStarted, ImagingStudyCompleted (the
 * Billing signal — §62), ImagingStudyCancelled, ImagingStudyAborted. NO_SHOW
 * and MarkStudyReady and the patient-verify confirmation have NO event (§177
 * names none — the Lab.2 `SpecimenAccepted` precedent). Every event is PHI-safe
 * (ADR-136 deny-list — `outcome_detail` never carried).
 *
 * `patient_id` / `encounter_id` are SNAPSHOTS of the order's (wrong-patient
 * safety, §54). `appointment_ref` / `equipment_ref` are opaque strings.
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
 * @property string $order_id
 * @property string $order_item_id
 * @property string $patient_id
 * @property string $encounter_id
 * @property string $accession_number
 * @property string $modality_code
 * @property string $service_code
 * @property string|null $body_site
 * @property string|null $laterality
 * @property string $status
 * @property CarbonImmutable|null $scheduled_for
 * @property string|null $appointment_ref
 * @property string|null $equipment_ref
 * @property string|null $verified_by_user_id
 * @property CarbonImmutable|null $verified_at
 * @property string|null $performed_by_user_id
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property string|null $outcome_status_code
 * @property string|null $outcome_detail
 * @property string|null $repeat_of_study_id
 * @property string $created_by_user_id
 * @property CarbonImmutable|null $occurred_at
 * @property CarbonImmutable|null $recorded_at
 * @property CarbonImmutable|null $updated_at
 * @property int $version
 */
final class ImagingStudy extends AggregateRoot
{
    /** @use HasFactory<ImagingStudyFactory> */
    use HasFactory;

    public const STATUS_SCHEDULED = 'SCHEDULED';

    public const STATUS_READY = 'READY';

    public const STATUS_IN_PROGRESS = 'IN_PROGRESS';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_CANCELLED = 'CANCELLED';

    public const STATUS_NO_SHOW = 'NO_SHOW';

    public const STATUS_ABORTED = 'ABORTED';

    /** §104: a study is in-flight while it can still be worked. */
    public const NON_TERMINAL_STATUSES = [
        self::STATUS_SCHEDULED,
        self::STATUS_READY,
        self::STATUS_IN_PROGRESS,
    ];

    protected $table = 'rad_imaging_studies';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'facility_id',
        'order_id',
        'order_item_id',
        'patient_id',
        'encounter_id',
        'accession_number',
        'modality_code',
        'service_code',
        'body_site',
        'laterality',
        'status',
        'scheduled_for',
        'appointment_ref',
        'equipment_ref',
        'verified_by_user_id',
        'verified_at',
        'performed_by_user_id',
        'started_at',
        'completed_at',
        'outcome_status_code',
        'outcome_detail',
        'repeat_of_study_id',
        'created_by_user_id',
        'occurred_at',
        'recorded_at',
        'version',
    ];

    protected static function newFactory(): ImagingStudyFactory
    {
        return ImagingStudyFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_for' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    /**
     * @param  array{
     *     id: string, tenantId: string, facilityId: string, orderId: string,
     *     orderItemId: string, patientId: string, encounterId: string,
     *     accessionNumber: string, modalityCode: string, serviceCode: string,
     *     bodySite: string|null, laterality: string|null,
     *     scheduledFor: CarbonImmutable|null, appointmentRef: string|null,
     *     equipmentRef: string|null, repeatOfStudyId: string|null,
     *     createdByUserId: string
     * }  $data
     */
    public static function schedule(array $data): self
    {
        $now = CarbonImmutable::now();

        $study = new self([
            'id' => $data['id'],
            'tenant_id' => $data['tenantId'],
            'facility_id' => $data['facilityId'],
            'order_id' => $data['orderId'],
            'order_item_id' => $data['orderItemId'],
            'patient_id' => $data['patientId'],
            'encounter_id' => $data['encounterId'],
            'accession_number' => $data['accessionNumber'],
            'modality_code' => $data['modalityCode'],
            'service_code' => $data['serviceCode'],
            'body_site' => $data['bodySite'],
            'laterality' => $data['laterality'],
            'status' => self::STATUS_SCHEDULED,
            'scheduled_for' => $data['scheduledFor'],
            'appointment_ref' => $data['appointmentRef'],
            'equipment_ref' => $data['equipmentRef'],
            'repeat_of_study_id' => $data['repeatOfStudyId'],
            'created_by_user_id' => $data['createdByUserId'],
            'occurred_at' => $now,
            'recorded_at' => $now,
        ]);

        $study->recordThat(new ImagingStudyScheduled(
            eventId: (string) Str::uuid7(),
            tenantId: $data['tenantId'],
            facilityId: $data['facilityId'],
            imagingStudyId: $data['id'],
            orderId: $data['orderId'],
            orderItemId: $data['orderItemId'],
            patientId: $data['patientId'],
            encounterId: $data['encounterId'],
            accessionNumber: $data['accessionNumber'],
            modalityCode: $data['modalityCode'],
            serviceCode: $data['serviceCode'],
            occurredAt: $now->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));

        return $study;
    }

    public function markReady(): void
    {
        $this->status = self::STATUS_READY;
    }

    public function start(string $performedByUserId, string $verifiedByUserId): void
    {
        $now = CarbonImmutable::now();
        $this->status = self::STATUS_IN_PROGRESS;
        $this->verified_by_user_id = $verifiedByUserId;
        $this->verified_at = $now;
        $this->performed_by_user_id = $performedByUserId;
        $this->started_at = $now;

        $this->recordThat(new ImagingStudyStarted(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            imagingStudyId: (string) $this->id,
            orderId: (string) $this->order_id,
            accessionNumber: (string) $this->accession_number,
            occurredAt: $now->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    public function complete(): void
    {
        $now = CarbonImmutable::now();
        $this->status = self::STATUS_COMPLETED;
        $this->completed_at = $now;

        $this->recordThat(new ImagingStudyCompleted(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            imagingStudyId: (string) $this->id,
            orderId: (string) $this->order_id,
            orderItemId: (string) $this->order_item_id,
            patientId: (string) $this->patient_id,
            encounterId: (string) $this->encounter_id,
            accessionNumber: (string) $this->accession_number,
            modalityCode: (string) $this->modality_code,
            serviceCode: (string) $this->service_code,
            occurredAt: $now->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    public function cancel(string $outcomeStatusCode, ?string $outcomeDetail): void
    {
        $now = CarbonImmutable::now();
        $this->status = self::STATUS_CANCELLED;
        $this->outcome_status_code = $outcomeStatusCode;
        $this->outcome_detail = ($outcomeDetail !== null && trim($outcomeDetail) !== '') ? trim($outcomeDetail) : null;

        $this->recordThat(new ImagingStudyCancelled(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            imagingStudyId: (string) $this->id,
            orderId: (string) $this->order_id,
            accessionNumber: (string) $this->accession_number,
            outcomeStatusCode: $outcomeStatusCode,
            occurredAt: $now->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    public function recordNoShow(): void
    {
        $this->status = self::STATUS_NO_SHOW;
        $this->outcome_status_code = self::STATUS_NO_SHOW;
    }

    public function abort(string $outcomeStatusCode, string $outcomeDetail): void
    {
        $now = CarbonImmutable::now();
        $this->status = self::STATUS_ABORTED;
        $this->outcome_status_code = $outcomeStatusCode;
        $this->outcome_detail = trim($outcomeDetail);

        $this->recordThat(new ImagingStudyAborted(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            imagingStudyId: (string) $this->id,
            orderId: (string) $this->order_id,
            accessionNumber: (string) $this->accession_number,
            outcomeStatusCode: $outcomeStatusCode,
            occurredAt: $now->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }
}
