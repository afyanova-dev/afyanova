<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Domain\Model;

use App\Domains\CareCoordination\Contracts\Event\CareHandoverAcknowledged;
use App\Domains\CareCoordination\Contracts\Event\CareHandoverInitiated;
use App\Domains\CareCoordination\Contracts\Event\ReferralAccepted;
use App\Domains\CareCoordination\Contracts\Event\ReferralCancelled;
use App\Domains\CareCoordination\Contracts\Event\ReferralCompleted;
use App\Domains\CareCoordination\Contracts\Event\ReferralExpired;
use App\Domains\CareCoordination\Contracts\Event\ReferralReceived;
use App\Domains\CareCoordination\Contracts\Event\ReferralRejected;
use App\Domains\CareCoordination\Contracts\Event\ReferralSent;
use App\Domains\CareCoordination\Database\Factories\ReferralFactory;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * Referral aggregate root (26-care-coordination-domain.md §6, §8; ADR-151
 * D-D/D-F).
 *
 * Emits ReferralSent, ReferralReceived, ReferralAccepted, ReferralRejected,
 * ReferralCompleted, ReferralExpired, ReferralCancelled,
 * CareHandoverInitiated (on send, when implies_handover), CareHandoverAcknowledged.
 *
 * @domain CareCoordination
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
 * @property string $patient_id
 * @property string $direction
 * @property bool $implies_handover
 * @property string|null $source_encounter_id
 * @property string $target_type
 * @property string $target_ref
 * @property string|null $specialty_requested
 * @property string $priority
 * @property string|null $clinical_reason_ref
 * @property string|null $consent_basis_ref
 * @property string $status
 * @property string|null $target_encounter_id
 * @property string $created_by_user_id
 * @property CarbonImmutable|null $occurred_at
 * @property CarbonImmutable|null $recorded_at
 * @property CarbonImmutable|null $updated_at
 * @property int $version
 */
final class Referral extends AggregateRoot
{
    /** @use HasFactory<ReferralFactory> */
    use HasFactory;

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_SENT = 'SENT';

    public const STATUS_RECEIVED = 'RECEIVED';

    public const STATUS_ACCEPTED = 'ACCEPTED';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_REJECTED = 'REJECTED';

    public const STATUS_CANCELLED = 'CANCELLED';

    public const STATUS_EXPIRED = 'EXPIRED';

    public const DIRECTION_OUTBOUND = 'OUTBOUND';

    public const DIRECTION_INBOUND = 'INBOUND';

    public const TARGET_TYPE_INTERNAL = 'INTERNAL';

    public const TARGET_TYPE_EXTERNAL = 'EXTERNAL';

    protected $table = 'care_referrals';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'facility_id',
        'patient_id',
        'direction',
        'implies_handover',
        'source_encounter_id',
        'target_type',
        'target_ref',
        'specialty_requested',
        'priority',
        'clinical_reason_ref',
        'consent_basis_ref',
        'status',
        'target_encounter_id',
        'created_by_user_id',
        'occurred_at',
        'recorded_at',
        'version',
    ];

    protected static function newFactory(): ReferralFactory
    {
        return ReferralFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'implies_handover' => 'boolean',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public static function create(
        string $id,
        string $tenantId,
        string $facilityId,
        string $patientId,
        string $direction,
        bool $impliesHandover,
        ?string $sourceEncounterId,
        string $targetType,
        string $targetRef,
        ?string $specialtyRequested,
        string $priority,
        ?string $clinicalReasonRef,
        ?string $consentBasisRef,
        string $createdByUserId,
        ?CarbonImmutable $occurredAt = null,
    ): self {
        $occurred = $occurredAt ?? CarbonImmutable::now();
        $recorded = CarbonImmutable::now();

        return new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'patient_id' => $patientId,
            'direction' => $direction,
            'implies_handover' => $impliesHandover,
            'source_encounter_id' => $sourceEncounterId,
            'target_type' => $targetType,
            'target_ref' => $targetRef,
            'specialty_requested' => $specialtyRequested,
            'priority' => $priority,
            'clinical_reason_ref' => $clinicalReasonRef,
            'consent_basis_ref' => $consentBasisRef,
            'status' => self::STATUS_DRAFT,
            'target_encounter_id' => null,
            'created_by_user_id' => $createdByUserId,
            'occurred_at' => $occurred,
            'recorded_at' => $recorded,
        ]);
    }

    public function send(): void
    {
        $this->status = self::STATUS_SENT;

        $now = CarbonImmutable::now()->toIso8601String();

        $this->recordThat(new ReferralSent(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            referralId: (string) $this->id,
            targetType: (string) $this->target_type,
            targetRef: (string) $this->target_ref,
            priority: (string) $this->priority,
            occurredAt: $now,
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));

        if ($this->implies_handover) {
            $this->recordThat(new CareHandoverInitiated(
                eventId: (string) Str::uuid7(),
                tenantId: (string) $this->tenant_id,
                facilityId: (string) $this->facility_id,
                referralId: (string) $this->id,
                occurredAt: $now,
                correlationId: CorrelationId::get(),
                causationId: Causation::get(),
            ));
        }
    }

    public function receive(): void
    {
        $this->status = self::STATUS_RECEIVED;

        $now = CarbonImmutable::now()->toIso8601String();

        $this->recordThat(new ReferralReceived(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            referralId: (string) $this->id,
            occurredAt: $now,
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    public function accept(): void
    {
        $this->status = self::STATUS_ACCEPTED;

        $now = CarbonImmutable::now()->toIso8601String();

        $this->recordThat(new ReferralAccepted(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            referralId: (string) $this->id,
            occurredAt: $now,
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    public function reject(): void
    {
        $this->status = self::STATUS_REJECTED;

        $now = CarbonImmutable::now()->toIso8601String();

        $this->recordThat(new ReferralRejected(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            referralId: (string) $this->id,
            occurredAt: $now,
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    public function complete(): void
    {
        $this->status = self::STATUS_COMPLETED;

        $now = CarbonImmutable::now()->toIso8601String();

        $this->recordThat(new ReferralCompleted(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            referralId: (string) $this->id,
            occurredAt: $now,
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    public function cancel(): void
    {
        $this->status = self::STATUS_CANCELLED;

        $now = CarbonImmutable::now()->toIso8601String();

        $this->recordThat(new ReferralCancelled(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            referralId: (string) $this->id,
            occurredAt: $now,
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    public function expire(): void
    {
        $this->status = self::STATUS_EXPIRED;

        $now = CarbonImmutable::now()->toIso8601String();

        $this->recordThat(new ReferralExpired(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            referralId: (string) $this->id,
            occurredAt: $now,
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    public function linkEncounter(string $encounterId): void
    {
        $this->target_encounter_id = $encounterId;
    }

    /**
     * The facility that acts on the receiving side of this referral
     * (§14: "inbound referral acceptance ... facility-scoped to the
     * receiving facility"). For an INTERNAL target, that is `target_ref`
     * (a facility_id in the same tenant); EXTERNAL referrals have no
     * receiving facility in this tenant, so `facility_id` (the source) is
     * the safe fallback — CC.1's receive-side commands are not expected to
     * be exercised against EXTERNAL referrals in practice.
     */
    public function receivingFacilityId(): string
    {
        return $this->target_type === self::TARGET_TYPE_INTERNAL
            ? (string) $this->target_ref
            : (string) $this->facility_id;
    }

    public function acknowledgeHandover(): void
    {
        $now = CarbonImmutable::now();

        $this->version = (int) $this->version + 1;
        $this->updated_at = $now;

        $this->recordThat(new CareHandoverAcknowledged(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            referralId: (string) $this->id,
            occurredAt: $now->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }
}
