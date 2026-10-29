<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain\Model;

use App\Domains\Theatre\Contracts\Event\CaseEventRecorded;
use App\Domains\Theatre\Database\Factories\CaseEventFactory;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * CaseEvent aggregate root (17-theatre-domain.md §84–§87; ADR-156 D-E) — one
 * coded event on a surgical case's intra-op timeline. Append-only: no
 * transitions, no updates (`Rls::enableAppendOnly` at the DB). Modelled as a
 * small AggregateRoot so `save()` drains `CaseEventRecorded` to the outbox
 * (the CC.2 `CareEpisodeEncounter` precedent).
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
 * @property string $event_type
 * @property CarbonImmutable|null $event_at
 * @property string|null $note
 * @property string $recorded_by_user_id
 * @property CarbonImmutable|null $occurred_at
 * @property CarbonImmutable|null $recorded_at
 * @property int $version
 */
final class CaseEvent extends AggregateRoot
{
    /** @use HasFactory<CaseEventFactory> */
    use HasFactory;

    protected $table = 'thr_case_events';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'facility_id',
        'case_id',
        'event_type',
        'event_at',
        'note',
        'recorded_by_user_id',
        'occurred_at',
        'recorded_at',
        'version',
    ];

    protected static function newFactory(): CaseEventFactory
    {
        return CaseEventFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_at' => 'immutable_datetime',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public static function record(
        string $id,
        string $tenantId,
        string $facilityId,
        string $caseId,
        string $eventType,
        CarbonImmutable $eventAt,
        ?string $note,
        string $recordedByUserId,
    ): self {
        $now = CarbonImmutable::now();

        $event = new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'case_id' => $caseId,
            'event_type' => $eventType,
            'event_at' => $eventAt,
            'note' => $note,
            'recorded_by_user_id' => $recordedByUserId,
            'occurred_at' => $eventAt,
            'recorded_at' => $now,
        ]);

        $event->recordThat(new CaseEventRecorded(
            eventId: (string) Str::uuid7(),
            tenantId: $tenantId,
            facilityId: $facilityId,
            caseEventId: $id,
            caseId: $caseId,
            eventType: $eventType,
            eventAt: $eventAt->toIso8601String(),
            occurredAt: $now->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));

        return $event;
    }
}
