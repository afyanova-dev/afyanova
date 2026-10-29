<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain\Model;

use App\Domains\Theatre\Database\Factories\OperativeNoteAmendmentFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An amendment appended to a signed operative note (17-theatre-domain.md
 * §81, §216; ADR-152 D-C).
 *
 * Plain, append-only Eloquent child model — NOT an AggregateRoot (the Room /
 * thr_case_team_assignments / Clinical clin_note_amendments precedent). Never
 * updated or deleted (no `updated_at`; `Rls::enableAppendOnly` blocks
 * UPDATE/DELETE at the DB). `version` is SHAPE / CONFORMANCE-ONLY — it exists
 * to satisfy the tenant-scoped-table invariant (IC §9.1 / §10 +
 * ConformanceCommand check 3b), stays 0 for the row's life, and is NOT an
 * optimistic-concurrency mechanism for the amendment rows (ADR-152 D-C
 * Governance — BLOCKER resolved by ratified conservative interpretation).
 * `amendment_text` / `reason` are ROW-ONLY (ADR-136 deny-list).
 *
 * @property CarbonImmutable|null $created_at
 * @property int $version
 */
class OperativeNoteAmendment extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<OperativeNoteAmendmentFactory> */
    use HasFactory;

    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'thr_operative_note_amendments';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'facility_id',
        'operative_note_id',
        'sequence',
        'amendment_text',
        'reason',
        'created_by_user_id',
        'created_at',
        'version',
    ];

    protected static function newFactory(): OperativeNoteAmendmentFactory
    {
        return OperativeNoteAmendmentFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'created_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }
}
