<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain\Model;

use App\Domains\Theatre\Contracts\Event\RecoveryHandoffAcknowledged;
use App\Domains\Theatre\Contracts\Event\RecoveryHandoffInitiated;
use App\Domains\Theatre\Database\Factories\RecoveryHandoffFactory;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * RecoveryHandoff aggregate root (17-theatre-domain.md §90–§92, §217; ADR-156
 * D-D) — the transfer from theatre to a recovery destination, with
 * receiving-team acknowledgment. One per SurgicalCase.
 *
 * Emits RecoveryHandoffInitiated / RecoveryHandoffAcknowledged (PHI-safe —
 * condition / pending actions NEVER carried).
 *
 * @domain Theatre
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
 * @property string $case_id
 * @property string $patient_id
 * @property string $encounter_id
 * @property string $destination_type
 * @property string|null $patient_condition
 * @property string|null $pending_actions
 * @property string|null $receiving_team_ref
 * @property string $status
 * @property string|null $acknowledged_by_user_id
 * @property CarbonImmutable|null $acknowledged_at
 * @property string $initiated_by_user_id
 * @property CarbonImmutable|null $occurred_at
 * @property CarbonImmutable|null $recorded_at
 * @property int $version
 */
final class RecoveryHandoff extends AggregateRoot
{
    /** @use HasFactory<RecoveryHandoffFactory> */
    use HasFactory;

    public const STATUS_INITIATED = 'INITIATED';

    public const STATUS_ACKNOWLEDGED = 'ACKNOWLEDGED';

    protected $table = 'thr_recovery_handoffs';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'facility_id',
        'case_id',
        'patient_id',
        'encounter_id',
        'destination_type',
        'patient_condition',
        'pending_actions',
        'receiving_team_ref',
        'status',
        'acknowledged_by_user_id',
        'acknowledged_at',
        'initiated_by_user_id',
        'occurred_at',
        'recorded_at',
        'version',
    ];

    protected static function newFactory(): RecoveryHandoffFactory
    {
        return RecoveryHandoffFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'acknowledged_at' => 'immutable_datetime',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public static function initiate(
        string $id,
        string $tenantId,
        string $facilityId,
        string $caseId,
        string $patientId,
        string $encounterId,
        string $destinationType,
        ?string $patientCondition,
        ?string $pendingActions,
        ?string $receivingTeamRef,
        string $initiatedByUserId,
        ?CarbonImmutable $occurredAt = null,
    ): self {
        $occurred = $occurredAt ?? CarbonImmutable::now();
        $recorded = CarbonImmutable::now();

        $handoff = new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'case_id' => $caseId,
            'patient_id' => $patientId,
            'encounter_id' => $encounterId,
            'destination_type' => $destinationType,
            'patient_condition' => $patientCondition,
            'pending_actions' => $pendingActions,
            'receiving_team_ref' => $receivingTeamRef,
            'status' => self::STATUS_INITIATED,
            'acknowledged_by_user_id' => null,
            'acknowledged_at' => null,
            'initiated_by_user_id' => $initiatedByUserId,
            'occurred_at' => $occurred,
            'recorded_at' => $recorded,
        ]);

        $handoff->recordThat(new RecoveryHandoffInitiated(
            eventId: (string) Str::uuid7(),
            tenantId: $tenantId,
            facilityId: $facilityId,
            recoveryHandoffId: $id,
            caseId: $caseId,
            patientId: $patientId,
            encounterId: $encounterId,
            destinationType: $destinationType,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));

        return $handoff;
    }

    public function acknowledge(string $acknowledgedByUserId): void
    {
        $this->status = self::STATUS_ACKNOWLEDGED;
        $this->acknowledged_by_user_id = $acknowledgedByUserId;
        $this->acknowledged_at = CarbonImmutable::now();

        $this->recordThat(new RecoveryHandoffAcknowledged(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            recoveryHandoffId: (string) $this->id,
            caseId: (string) $this->case_id,
            acknowledgedByUserId: $acknowledgedByUserId,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }
}
