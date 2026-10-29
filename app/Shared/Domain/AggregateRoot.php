<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use App\Shared\Concurrency\HasVersionColumn;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Base for every aggregate root (IMPLEMENTATION-CONTRACTS.md §4.1).
 *
 * Eloquent-model-as-aggregate-root: `HasUuid` (UUIDv7 primary key),
 * `HasVersionColumn` (optimistic concurrency, §9.1 — `version` starts at 0),
 * `BelongsToTenant` (tenant scope + RLS backstop), and an in-memory
 * recorded-events buffer the repository drains to the outbox on `save()` (§7.2).
 *
 * A concrete aggregate declares its own `$fillable`, `$table`, enum `$casts`,
 * and the `@domain / @authority / @tenant / @facility` class docblock (§4.1).
 */
abstract class AggregateRoot extends Model
{
    use BelongsToTenant;
    use HasUuid;
    use HasVersionColumn;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var array<string, mixed> */
    protected $attributes = [
        'version' => 0,
    ];

    /** @var array<string, string> */
    protected $casts = [
        'version' => 'integer',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    /** @var list<object> */
    private array $recordedEvents = [];

    protected function recordThat(object $event): void
    {
        $this->recordedEvents[] = $event;
    }

    /**
     * The events recorded since the last release, clearing the buffer. The
     * repository calls this inside `save()` and hands each event to the
     * `OutboxWriter` — one transaction with the state change (§7.2).
     *
     * @return list<object>
     */
    public function releaseEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];

        return $events;
    }
}
