<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Domain\Model;

use App\Domains\CareCoordination\Contracts\Event\CareEpisodeClosed;
use App\Domains\CareCoordination\Contracts\Event\CareEpisodeOpened;
use App\Domains\CareCoordination\Contracts\Event\CareEpisodeTransferredOut;
use App\Domains\CareCoordination\Database\Factories\CareEpisodeFactory;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * CareEpisode aggregate root (26-care-coordination-domain.md §6, §8; ADR-153
 * D-B/D-C) — a longitudinal container that links encounters into one care
 * journey. It never holds clinical / financial / diagnostic records
 * (Invariant 7.2); it links (via CareEpisodeEncounter), it does not contain.
 *
 * Emits CareEpisodeOpened, CareEpisodeClosed, CareEpisodeTransferredOut. Its
 * lifecycle is independent of its links' (Invariant 7.8).
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
 * @property string $type
 * @property string|null $subtype
 * @property string $status
 * @property string|null $override_group
 * @property CarbonImmutable|null $opened_at
 * @property CarbonImmutable|null $closed_at
 * @property string $created_by_user_id
 * @property CarbonImmutable|null $occurred_at
 * @property CarbonImmutable|null $recorded_at
 * @property CarbonImmutable|null $updated_at
 * @property int $version
 */
final class CareEpisode extends AggregateRoot
{
    /** @use HasFactory<CareEpisodeFactory> */
    use HasFactory;

    public const STATUS_OPEN = 'OPEN';

    public const STATUS_CLOSED = 'CLOSED';

    public const STATUS_TRANSFERRED_OUT = 'TRANSFERRED_OUT';

    protected $table = 'care_episodes';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'facility_id',
        'patient_id',
        'type',
        'subtype',
        'status',
        'override_group',
        'opened_at',
        'closed_at',
        'created_by_user_id',
        'occurred_at',
        'recorded_at',
        'version',
    ];

    protected static function newFactory(): CareEpisodeFactory
    {
        return CareEpisodeFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opened_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public static function open(
        string $id,
        string $tenantId,
        string $facilityId,
        string $patientId,
        string $type,
        ?string $subtype,
        ?string $overrideGroup,
        bool $override,
        string $createdByUserId,
        ?CarbonImmutable $occurredAt = null,
    ): self {
        $occurred = $occurredAt ?? CarbonImmutable::now();
        $recorded = CarbonImmutable::now();

        $episode = new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'patient_id' => $patientId,
            'type' => $type,
            'subtype' => $subtype,
            'status' => self::STATUS_OPEN,
            'override_group' => $overrideGroup,
            'opened_at' => $occurred,
            'closed_at' => null,
            'created_by_user_id' => $createdByUserId,
            'occurred_at' => $occurred,
            'recorded_at' => $recorded,
        ]);

        $episode->recordThat(new CareEpisodeOpened(
            eventId: (string) Str::uuid7(),
            tenantId: $tenantId,
            facilityId: $facilityId,
            careEpisodeId: $id,
            patientId: $patientId,
            type: $type,
            subtype: $subtype,
            override: $override,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));

        return $episode;
    }

    public function close(): void
    {
        $this->status = self::STATUS_CLOSED;
        $this->closed_at = CarbonImmutable::now();

        $this->recordThat(new CareEpisodeClosed(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            careEpisodeId: (string) $this->id,
            patientId: (string) $this->patient_id,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    public function markTransferredOut(): void
    {
        $this->status = self::STATUS_TRANSFERRED_OUT;
        $this->closed_at = CarbonImmutable::now();

        $this->recordThat(new CareEpisodeTransferredOut(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            careEpisodeId: (string) $this->id,
            patientId: (string) $this->patient_id,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }
}
