<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain\Model;

use App\Domains\Laboratory\Contracts\Event\CriticalLabResultAcknowledged;
use App\Domains\Laboratory\Contracts\Event\CriticalLabResultIdentified;
use App\Domains\Laboratory\Database\Factories\LabCriticalResultFactory;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * Laboratory critical-result aggregate root (14-laboratory-domain.md §53–§56,
 * §160; ADR-161 D-D). One per `lab_results` row flagged critical.
 *
 * Emits CriticalLabResultIdentified, CriticalLabResultAcknowledged — PHI-safe
 * (ADR-136 deny-list — clinical_context / acknowledgement_note never carried).
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
 * @property string $result_id
 * @property string $order_id
 * @property string $patient_id
 * @property string $test_code
 * @property string $parameter_code
 * @property string $severity
 * @property string|null $clinical_context
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
final class LabCriticalResult extends AggregateRoot
{
    /** @use HasFactory<LabCriticalResultFactory> */
    use HasFactory;

    public const STATUS_INITIATED = 'INITIATED';

    public const STATUS_ACKNOWLEDGED = 'ACKNOWLEDGED';

    protected $table = 'lab_critical_results';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'facility_id',
        'result_id',
        'order_id',
        'patient_id',
        'test_code',
        'parameter_code',
        'severity',
        'clinical_context',
        'status',
        'identified_by_user_id',
        'identified_at',
        'acknowledged_by_user_id',
        'acknowledged_at',
        'acknowledgement_note',
        'created_by_user_id',
        'occurred_at',
        'recorded_at',
        'version',
    ];

    protected static function newFactory(): LabCriticalResultFactory
    {
        return LabCriticalResultFactory::new();
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

    public static function identify(
        string $id,
        string $tenantId,
        string $facilityId,
        string $resultId,
        string $orderId,
        string $patientId,
        string $testCode,
        string $parameterCode,
        string $severity,
        ?string $clinicalContext,
        string $identifiedByUserId,
    ): self {
        $now = CarbonImmutable::now();

        $critical = new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'result_id' => $resultId,
            'order_id' => $orderId,
            'patient_id' => $patientId,
            'test_code' => $testCode,
            'parameter_code' => $parameterCode,
            'severity' => $severity,
            'clinical_context' => $clinicalContext,
            'status' => self::STATUS_INITIATED,
            'identified_by_user_id' => $identifiedByUserId,
            'identified_at' => $now,
            'created_by_user_id' => $identifiedByUserId,
            'occurred_at' => $now,
            'recorded_at' => $now,
        ]);

        $critical->recordThat(new CriticalLabResultIdentified(
            eventId: (string) Str::uuid7(),
            tenantId: $tenantId,
            facilityId: $facilityId,
            criticalResultId: $id,
            resultId: $resultId,
            orderId: $orderId,
            patientId: $patientId,
            testCode: $testCode,
            parameterCode: $parameterCode,
            severity: $severity,
            occurredAt: $now->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));

        return $critical;
    }

    public function acknowledge(string $acknowledgedByUserId, ?string $note): void
    {
        $now = CarbonImmutable::now();
        $this->status = self::STATUS_ACKNOWLEDGED;
        $this->acknowledged_by_user_id = $acknowledgedByUserId;
        $this->acknowledged_at = $now;
        $this->acknowledgement_note = ($note !== null && trim($note) !== '') ? trim($note) : null;

        $this->recordThat(new CriticalLabResultAcknowledged(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            criticalResultId: (string) $this->id,
            resultId: (string) $this->result_id,
            orderId: (string) $this->order_id,
            patientId: (string) $this->patient_id,
            occurredAt: $now->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }
}
