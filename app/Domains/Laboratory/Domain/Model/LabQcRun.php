<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain\Model;

use App\Domains\Laboratory\Contracts\Event\QcRunFailed;
use App\Domains\Laboratory\Contracts\Event\QcRunRecorded;
use App\Domains\Laboratory\Database\Factories\LabQcRunFactory;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * Laboratory QC-run aggregate root (14-laboratory-domain.md §83–§85, §153;
 * ADR-163 D-D).
 *
 * Emits QcRunRecorded (always) and QcRunFailed (when status FAIL) — PHI-safe
 * (ADR-136 deny-list — review_note / observed_value never carried).
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
 * @property string $analyzer_ref
 * @property string|null $test_definition_id
 * @property string|null $test_code
 * @property string $control_material
 * @property string|null $control_level
 * @property string|null $expected_low
 * @property string|null $expected_high
 * @property string $observed_value
 * @property string|null $unit
 * @property string $status
 * @property string|null $reviewed_by_user_id
 * @property CarbonImmutable|null $reviewed_at
 * @property string|null $review_note
 * @property string $performed_by_user_id
 * @property CarbonImmutable|null $performed_at
 * @property string $created_by_user_id
 * @property CarbonImmutable|null $occurred_at
 * @property CarbonImmutable|null $recorded_at
 * @property CarbonImmutable|null $updated_at
 * @property int $version
 */
final class LabQcRun extends AggregateRoot
{
    /** @use HasFactory<LabQcRunFactory> */
    use HasFactory;

    public const STATUS_PASS = 'PASS';

    public const STATUS_FAIL = 'FAIL';

    public const STATUS_WARNING = 'WARNING';

    protected $table = 'lab_qc_runs';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'facility_id',
        'analyzer_ref',
        'test_definition_id',
        'test_code',
        'control_material',
        'control_level',
        'expected_low',
        'expected_high',
        'observed_value',
        'unit',
        'status',
        'reviewed_by_user_id',
        'reviewed_at',
        'review_note',
        'performed_by_user_id',
        'performed_at',
        'created_by_user_id',
        'occurred_at',
        'recorded_at',
        'version',
    ];

    protected static function newFactory(): LabQcRunFactory
    {
        return LabQcRunFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reviewed_at' => 'immutable_datetime',
            'performed_at' => 'immutable_datetime',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    /**
     * @param  array{
     *     qcRunId: string, tenantId: string, facilityId: string, analyzerRef: string,
     *     testDefinitionId: string|null, testCode: string|null, controlMaterial: string,
     *     controlLevel: string|null, expectedLow: float|null, expectedHigh: float|null,
     *     observedValue: float, unit: string|null, status: string, performedByUserId: string,
     *     occurredAt: CarbonImmutable|null
     * }  $data
     */
    public static function record(array $data): self
    {
        $now = CarbonImmutable::now();
        $occurred = $data['occurredAt'] ?? $now;

        $run = new self([
            'id' => $data['qcRunId'],
            'tenant_id' => $data['tenantId'],
            'facility_id' => $data['facilityId'],
            'analyzer_ref' => $data['analyzerRef'],
            'test_definition_id' => $data['testDefinitionId'],
            'test_code' => $data['testCode'],
            'control_material' => $data['controlMaterial'],
            'control_level' => $data['controlLevel'],
            'expected_low' => $data['expectedLow'],
            'expected_high' => $data['expectedHigh'],
            'observed_value' => $data['observedValue'],
            'unit' => $data['unit'],
            'status' => $data['status'],
            'performed_by_user_id' => $data['performedByUserId'],
            'performed_at' => $occurred,
            'created_by_user_id' => $data['performedByUserId'],
            'occurred_at' => $occurred,
            'recorded_at' => $now,
        ]);

        $run->recordThat(new QcRunRecorded(
            eventId: (string) Str::uuid7(),
            tenantId: $data['tenantId'],
            facilityId: $data['facilityId'],
            qcRunId: $data['qcRunId'],
            analyzerRef: $data['analyzerRef'],
            testDefinitionId: $data['testDefinitionId'],
            status: $data['status'],
            occurredAt: $now->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));

        if ($data['status'] === self::STATUS_FAIL) {
            $run->recordThat(new QcRunFailed(
                eventId: (string) Str::uuid7(),
                tenantId: $data['tenantId'],
                facilityId: $data['facilityId'],
                qcRunId: $data['qcRunId'],
                analyzerRef: $data['analyzerRef'],
                testDefinitionId: $data['testDefinitionId'],
                occurredAt: $now->toIso8601String(),
                correlationId: CorrelationId::get(),
                causationId: Causation::get(),
            ));
        }

        return $run;
    }

    public function review(string $reviewedByUserId, ?string $note): void
    {
        $this->reviewed_by_user_id = $reviewedByUserId;
        $this->reviewed_at = CarbonImmutable::now();
        $this->review_note = ($note !== null && trim($note) !== '') ? trim($note) : null;
    }
}
