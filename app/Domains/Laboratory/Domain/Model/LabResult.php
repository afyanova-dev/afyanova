<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain\Model;

use App\Domains\Laboratory\Contracts\Event\LabResultAmended;
use App\Domains\Laboratory\Contracts\Event\LabResultCancelled;
use App\Domains\Laboratory\Contracts\Event\LabResultEntered;
use App\Domains\Laboratory\Contracts\Event\LabResultReleased;
use App\Domains\Laboratory\Contracts\Event\LabResultVerified;
use App\Domains\Laboratory\Database\Factories\LabResultFactory;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * Laboratory result aggregate root — one measurable parameter
 * (14-laboratory-domain.md §45–§70, §151, §157; ADR-159 D-D/D-E).
 *
 * Emits LabResultEntered, LabResultVerified, LabResultReleased. `ReviewResult`
 * has no event (an internal step — the §138 list names none). Every event is
 * PHI-safe (ADR-136 deny-list — value_text / value_numeric / value_code never
 * carried; only identifiers + the coded abnormal_flag).
 *
 * @domain Laboratory
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
 * @property string $specimen_id
 * @property string $patient_id
 * @property string $test_definition_id
 * @property string $test_code
 * @property string $parameter_code
 * @property string $parameter_name
 * @property string $value_type
 * @property string|null $value_numeric
 * @property string|null $value_code
 * @property string|null $value_text
 * @property string|null $unit
 * @property string|null $reference_low
 * @property string|null $reference_high
 * @property string|null $reference_text
 * @property string|null $reference_range_source
 * @property string|null $abnormal_flag
 * @property string $result_source
 * @property string|null $method
 * @property string $status
 * @property string|null $release_hold_reason
 * @property int $amendment_count
 * @property string|null $cancellation_reason
 * @property string $entered_by_user_id
 * @property CarbonImmutable|null $entered_at
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
final class LabResult extends AggregateRoot
{
    /** @use HasFactory<LabResultFactory> */
    use HasFactory;

    public const STATUS_ENTERED = 'ENTERED';

    public const STATUS_REVIEWED = 'REVIEWED';

    public const STATUS_VERIFIED = 'VERIFIED';

    public const STATUS_RELEASED = 'RELEASED';

    /** Reserved for Lab.3b. */
    public const STATUS_CANCELLED = 'CANCELLED';

    /** Reserved for Lab.3b. */
    public const STATUS_AMENDED = 'AMENDED';

    public const VALUE_TYPE_NUMERIC = 'NUMERIC';

    public const VALUE_TYPE_CODED = 'CODED';

    public const VALUE_TYPE_TEXT = 'TEXT';

    public const FLAG_NORMAL = 'NORMAL';

    public const FLAG_HIGH = 'HIGH';

    public const FLAG_LOW = 'LOW';

    /** ADR-161 (Lab.3c) — set when a `LabCriticalResult` is recorded against this result. */
    public const FLAG_CRITICAL = 'CRITICAL';

    protected $table = 'lab_results';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'facility_id',
        'order_id',
        'order_item_id',
        'specimen_id',
        'patient_id',
        'test_definition_id',
        'test_code',
        'parameter_code',
        'parameter_name',
        'value_type',
        'value_numeric',
        'value_code',
        'value_text',
        'unit',
        'reference_low',
        'reference_high',
        'reference_text',
        'reference_range_source',
        'abnormal_flag',
        'result_source',
        'method',
        'status',
        'release_hold_reason',
        'amendment_count',
        'cancellation_reason',
        'entered_by_user_id',
        'entered_at',
        'reviewed_by_user_id',
        'reviewed_at',
        'verified_by_user_id',
        'verified_at',
        'released_by_user_id',
        'released_at',
        'created_by_user_id',
        'occurred_at',
        'recorded_at',
        'version',
    ];

    protected static function newFactory(): LabResultFactory
    {
        return LabResultFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entered_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'amendment_count' => 'integer',
            'version' => 'integer',
        ];
    }

    /**
     * @param  array{
     *     resultId: string, tenantId: string, facilityId: string, orderId: string,
     *     orderItemId: string, specimenId: string, patientId: string,
     *     testDefinitionId: string, testCode: string, parameterCode: string,
     *     parameterName: string, valueType: string, valueNumeric: float|null,
     *     valueCode: string|null, valueText: string|null, unit: string|null,
     *     referenceLow: float|null, referenceHigh: float|null, referenceText: string|null,
     *     referenceRangeSource: string|null, abnormalFlag: string|null,
     *     method: string|null, enteredByUserId: string, occurredAt: CarbonImmutable|null
     * }  $data
     */
    public static function record(array $data): self
    {
        $now = CarbonImmutable::now();
        $occurred = $data['occurredAt'] ?? $now;

        $result = new self([
            'id' => $data['resultId'],
            'tenant_id' => $data['tenantId'],
            'facility_id' => $data['facilityId'],
            'order_id' => $data['orderId'],
            'order_item_id' => $data['orderItemId'],
            'specimen_id' => $data['specimenId'],
            'patient_id' => $data['patientId'],
            'test_definition_id' => $data['testDefinitionId'],
            'test_code' => $data['testCode'],
            'parameter_code' => $data['parameterCode'],
            'parameter_name' => $data['parameterName'],
            'value_type' => $data['valueType'],
            'value_numeric' => $data['valueNumeric'],
            'value_code' => $data['valueCode'],
            'value_text' => $data['valueText'],
            'unit' => $data['unit'],
            'reference_low' => $data['referenceLow'],
            'reference_high' => $data['referenceHigh'],
            'reference_text' => $data['referenceText'],
            'reference_range_source' => $data['referenceRangeSource'],
            'abnormal_flag' => $data['abnormalFlag'],
            'result_source' => 'MANUAL',
            'method' => $data['method'],
            'status' => self::STATUS_ENTERED,
            'release_hold_reason' => null,
            'amendment_count' => 0,
            'cancellation_reason' => null,
            'entered_by_user_id' => $data['enteredByUserId'],
            'entered_at' => $now,
            'created_by_user_id' => $data['enteredByUserId'],
            'occurred_at' => $occurred,
            'recorded_at' => $now,
        ]);

        $result->recordThat(new LabResultEntered(
            eventId: (string) Str::uuid7(),
            tenantId: $data['tenantId'],
            facilityId: $data['facilityId'],
            resultId: $data['resultId'],
            orderId: $data['orderId'],
            specimenId: $data['specimenId'],
            patientId: $data['patientId'],
            testCode: $data['testCode'],
            parameterCode: $data['parameterCode'],
            abnormalFlag: $data['abnormalFlag'],
            occurredAt: $now->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));

        return $result;
    }

    public function review(string $reviewedByUserId): void
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

        $this->recordThat(new LabResultVerified(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            resultId: (string) $this->id,
            orderId: (string) $this->order_id,
            patientId: (string) $this->patient_id,
            testCode: (string) $this->test_code,
            parameterCode: (string) $this->parameter_code,
            abnormalFlag: $this->abnormal_flag,
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

        $this->recordThat(new LabResultReleased(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            resultId: (string) $this->id,
            orderId: (string) $this->order_id,
            patientId: (string) $this->patient_id,
            testCode: (string) $this->test_code,
            parameterCode: (string) $this->parameter_code,
            abnormalFlag: $this->abnormal_flag,
            occurredAt: $now->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    /**
     * ADR-160 (Lab.3b) — apply a correction to a finalized result. The old→new
     * values + reason are recorded on an append-only `lab_result_amendments`
     * row by the handler; here the corrected value is applied and the row is
     * dirtied via `amendment_count` (the T.2 precedent — a 2nd amendment on an
     * already-AMENDED result re-assigns `status` to the same value, so without
     * this the optimistic-lock UPDATE would be a no-op).
     *
     * @param  array{valueType: string, valueNumeric: float|null, valueCode: string|null, valueText: string|null, unit: string|null, abnormalFlag: string|null}  $corrected
     */
    public function amend(array $corrected): void
    {
        $this->value_type = $corrected['valueType'];
        $this->value_numeric = $corrected['valueNumeric'] !== null ? (string) $corrected['valueNumeric'] : null;
        $this->value_code = $corrected['valueCode'];
        $this->value_text = $corrected['valueText'];
        $this->unit = $corrected['unit'];
        $this->abnormal_flag = $corrected['abnormalFlag'];
        $this->status = self::STATUS_AMENDED;
        $this->amendment_count = (int) $this->amendment_count + 1;

        $this->recordThat(new LabResultAmended(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            resultId: (string) $this->id,
            orderId: (string) $this->order_id,
            patientId: (string) $this->patient_id,
            testCode: (string) $this->test_code,
            parameterCode: (string) $this->parameter_code,
            abnormalFlag: $this->abnormal_flag,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    /**
     * ADR-161 (Lab.3c) — mark this result CRITICAL. Eventless (the
     * `LabCriticalResult` aggregate emits `CriticalLabResultIdentified`);
     * `amendment_count` keeps the row dirty for the optimistic-lock UPDATE.
     */
    public function flagCritical(): void
    {
        $this->abnormal_flag = self::FLAG_CRITICAL;
        $this->amendment_count = (int) $this->amendment_count + 1;
    }

    /**
     * ADR-163 (Lab.5) — block this result from release (a failed QC run).
     * Eventless operational annotation checked by `LabResultReleaseGate`.
     */
    public function applyReleaseHold(string $reason): void
    {
        $this->release_hold_reason = $reason;
        $this->amendment_count = (int) $this->amendment_count + 1;
    }

    /** ADR-163 (Lab.5) — lift a release hold (the QC run was reviewed). */
    public function clearReleaseHold(): void
    {
        $this->release_hold_reason = null;
        $this->amendment_count = (int) $this->amendment_count + 1;
    }

    /**
     * ADR-160 (Lab.3b) — invalidate a result (§63). `reason` is row-only +
     * audit; not carried in the event.
     */
    public function cancelResult(string $reason): void
    {
        $this->status = self::STATUS_CANCELLED;
        $this->cancellation_reason = trim($reason);
        $this->amendment_count = (int) $this->amendment_count + 1;

        $this->recordThat(new LabResultCancelled(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            resultId: (string) $this->id,
            orderId: (string) $this->order_id,
            patientId: (string) $this->patient_id,
            testCode: (string) $this->test_code,
            parameterCode: (string) $this->parameter_code,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }
}
