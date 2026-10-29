<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Domain\Model;

use App\Domains\CareCoordination\Contracts\Event\ReferralFeedbackCompleted;
use App\Domains\CareCoordination\Contracts\Event\ReferralFeedbackNotProvided;
use App\Domains\CareCoordination\Contracts\Event\ReferralFeedbackRecorded;
use App\Domains\CareCoordination\Database\Factories\ReferralFeedbackFactory;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * ReferralFeedback aggregate root — its own lifecycle, independent of the
 * referral's (26-care-coordination-domain.md §6, §7 Invariant 7.7, §8;
 * ADR-151 D-D/D-F).
 *
 * CC.1 creates exactly one feedback row per referral (`sequence` = 1,
 * auto-created EXPECTED by `AcceptReferralHandler`) — full multi-entry
 * append history is a T.2+-style deferral.
 *
 * Emits ReferralFeedbackRecorded, ReferralFeedbackCompleted,
 * ReferralFeedbackNotProvided. NOT emitted on creation (EXPECTED) — D-J's
 * 12-event list has no "created"/"expected" event, the same partial-coverage
 * pattern already accepted for Theatre's checklist entries.
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
 * @property string $referral_id
 * @property int $sequence
 * @property string|null $kind
 * @property CarbonImmutable|null $received_at
 * @property string|null $received_via
 * @property string|null $summary
 * @property string|null $outcome
 * @property string $status
 * @property string $created_by_user_id
 * @property CarbonImmutable|null $occurred_at
 * @property CarbonImmutable|null $recorded_at
 * @property int $version
 */
final class ReferralFeedback extends AggregateRoot
{
    /** @use HasFactory<ReferralFeedbackFactory> */
    use HasFactory;

    public const STATUS_EXPECTED = 'EXPECTED';

    public const STATUS_PARTIAL = 'PARTIAL';

    public const STATUS_RECEIVED = 'RECEIVED';

    public const STATUS_NOT_PROVIDED = 'NOT_PROVIDED';

    protected $table = 'care_referral_feedback';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'facility_id',
        'referral_id',
        'sequence',
        'kind',
        'received_at',
        'received_via',
        'summary',
        'outcome',
        'status',
        'created_by_user_id',
        'occurred_at',
        'recorded_at',
        'version',
    ];

    protected static function newFactory(): ReferralFeedbackFactory
    {
        return ReferralFeedbackFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'received_at' => 'immutable_datetime',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public static function createExpected(
        string $id,
        string $tenantId,
        string $facilityId,
        string $referralId,
        string $createdByUserId,
    ): self {
        $now = CarbonImmutable::now();

        return new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'referral_id' => $referralId,
            'sequence' => 1,
            'kind' => null,
            'received_at' => null,
            'received_via' => null,
            'summary' => null,
            'outcome' => null,
            'status' => self::STATUS_EXPECTED,
            'created_by_user_id' => $createdByUserId,
            'occurred_at' => $now,
            'recorded_at' => $now,
        ]);
    }

    public function recordContent(
        string $kind,
        ?string $summary,
        ?string $outcome,
        ?string $receivedVia,
        ?CarbonImmutable $receivedAt = null,
    ): void {
        $this->status = self::STATUS_PARTIAL;
        $this->kind = $kind;
        $this->summary = $summary;
        $this->outcome = $outcome;
        $this->received_via = $receivedVia;
        $this->received_at = $receivedAt ?? CarbonImmutable::now();

        $now = CarbonImmutable::now()->toIso8601String();

        $this->recordThat(new ReferralFeedbackRecorded(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            referralId: (string) $this->referral_id,
            feedbackId: (string) $this->id,
            kind: $this->kind,
            occurredAt: $now,
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    public function complete(): void
    {
        $this->status = self::STATUS_RECEIVED;

        $now = CarbonImmutable::now()->toIso8601String();

        $this->recordThat(new ReferralFeedbackCompleted(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            referralId: (string) $this->referral_id,
            feedbackId: (string) $this->id,
            occurredAt: $now,
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    public function markNotProvided(): void
    {
        $this->status = self::STATUS_NOT_PROVIDED;

        $now = CarbonImmutable::now()->toIso8601String();

        $this->recordThat(new ReferralFeedbackNotProvided(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            referralId: (string) $this->referral_id,
            feedbackId: (string) $this->id,
            occurredAt: $now,
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }
}
