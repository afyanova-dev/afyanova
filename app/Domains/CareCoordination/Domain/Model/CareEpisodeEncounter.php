<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Domain\Model;

use App\Domains\CareCoordination\Contracts\Event\EncounterDetachedFromEpisode;
use App\Domains\CareCoordination\Contracts\Event\EncounterLinkedToEpisode;
use App\Domains\CareCoordination\Database\Factories\CareEpisodeEncounterFactory;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * CareEpisodeEncounter aggregate root (26-care-coordination-domain.md §6, §8;
 * ADR-153 D-B/D-C) — the membership link between a CareEpisode and an
 * Encounter. Its own aggregate (the ReferralFeedback precedent) because it
 * has a real LINKED → DETACHED lifecycle and its own two events, and §7.8
 * decouples it from the episode's lifecycle.
 *
 * Emits EncounterLinkedToEpisode on creation, EncounterDetachedFromEpisode on
 * detach. Detach is a status transition — the row is kept, never hard-deleted
 * (§256). `role` / `added_reason` / `detach_reason` are ROW-ONLY.
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
 * @property string $care_episode_id
 * @property string $encounter_id
 * @property string $patient_id
 * @property string|null $role
 * @property string $status
 * @property CarbonImmutable|null $added_at
 * @property string $added_by_user_id
 * @property string|null $added_reason
 * @property CarbonImmutable|null $detached_at
 * @property string|null $detached_by_user_id
 * @property string|null $detach_reason
 * @property string $created_by_user_id
 * @property CarbonImmutable|null $occurred_at
 * @property CarbonImmutable|null $recorded_at
 * @property CarbonImmutable|null $updated_at
 * @property int $version
 */
final class CareEpisodeEncounter extends AggregateRoot
{
    /** @use HasFactory<CareEpisodeEncounterFactory> */
    use HasFactory;

    public const STATUS_LINKED = 'LINKED';

    public const STATUS_DETACHED = 'DETACHED';

    protected $table = 'care_episode_encounters';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'facility_id',
        'care_episode_id',
        'encounter_id',
        'patient_id',
        'role',
        'status',
        'added_at',
        'added_by_user_id',
        'added_reason',
        'detached_at',
        'detached_by_user_id',
        'detach_reason',
        'created_by_user_id',
        'occurred_at',
        'recorded_at',
        'version',
    ];

    protected static function newFactory(): CareEpisodeEncounterFactory
    {
        return CareEpisodeEncounterFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'added_at' => 'immutable_datetime',
            'detached_at' => 'immutable_datetime',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public static function link(
        string $id,
        string $tenantId,
        string $facilityId,
        string $careEpisodeId,
        string $encounterId,
        string $patientId,
        ?string $role,
        ?string $addedReason,
        string $createdByUserId,
        ?CarbonImmutable $occurredAt = null,
    ): self {
        $occurred = $occurredAt ?? CarbonImmutable::now();
        $recorded = CarbonImmutable::now();

        $link = new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'care_episode_id' => $careEpisodeId,
            'encounter_id' => $encounterId,
            'patient_id' => $patientId,
            'role' => $role,
            'status' => self::STATUS_LINKED,
            'added_at' => $occurred,
            'added_by_user_id' => $createdByUserId,
            'added_reason' => $addedReason,
            'detached_at' => null,
            'detached_by_user_id' => null,
            'detach_reason' => null,
            'created_by_user_id' => $createdByUserId,
            'occurred_at' => $occurred,
            'recorded_at' => $recorded,
        ]);

        $link->recordThat(new EncounterLinkedToEpisode(
            eventId: (string) Str::uuid7(),
            tenantId: $tenantId,
            facilityId: $facilityId,
            careEpisodeEncounterId: $id,
            careEpisodeId: $careEpisodeId,
            encounterId: $encounterId,
            patientId: $patientId,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));

        return $link;
    }

    public function detach(string $detachedByUserId, string $detachReason): void
    {
        $this->status = self::STATUS_DETACHED;
        $this->detached_at = CarbonImmutable::now();
        $this->detached_by_user_id = $detachedByUserId;
        $this->detach_reason = $detachReason;

        $this->recordThat(new EncounterDetachedFromEpisode(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            careEpisodeEncounterId: (string) $this->id,
            careEpisodeId: (string) $this->care_episode_id,
            encounterId: (string) $this->encounter_id,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }
}
