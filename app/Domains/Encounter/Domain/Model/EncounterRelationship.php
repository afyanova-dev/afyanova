<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Domain\Model;

use App\Domains\Encounter\Database\Factories\EncounterRelationshipFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An encounter-to-encounter relationship (11-encounter-domain.md §172;
 * ADR-151 D-C).
 *
 * Plain, append-only Eloquent child model — NOT an AggregateRoot (no
 * relationship-specific event named anywhere in canon, the Room precedent).
 * Written directly by `RecordEncounterRelationshipHandler`; never updated or
 * deleted.
 *
 * `version` is SHAPE / CONFORMANCE-ONLY — it satisfies the tenant-scoped-table
 * invariant enforced by ConformanceCommand (check 3b), stays 0 for the row's
 * life, and is NOT an optimistic-concurrency mechanism (ADR-151 Governance
 * note — BLOCKER resolved by the standing conservative interpretation,
 * ratified 2026-09-05).
 *
 * @property int $version
 */
class EncounterRelationship extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<EncounterRelationshipFactory> */
    use HasFactory;

    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'enc_encounter_relationships';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'facility_id',
        'encounter_id',
        'related_encounter_id',
        'relationship_type',
        'created_by_user_id',
        'created_at',
        'version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }
}
