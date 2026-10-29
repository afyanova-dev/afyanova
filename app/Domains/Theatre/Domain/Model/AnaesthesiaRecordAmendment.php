<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain\Model;

use App\Domains\Theatre\Database\Factories\AnaesthesiaRecordAmendmentFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An amendment appended to a signed anaesthesia record (17-theatre-domain.md;
 * ADR-155 D-B). The `thr_operative_note_amendments` precedent EXACTLY.
 *
 * Plain, append-only Eloquent child model — NOT an AggregateRoot. Never
 * updated or deleted (no `updated_at`; `Rls::enableAppendOnly` blocks
 * UPDATE/DELETE). `version` is SHAPE / CONFORMANCE-ONLY (the ADR-152 D-C
 * ratified interpretation — stays 0, not optimistic concurrency).
 * `amendment_text` / `reason` are ROW-ONLY (ADR-136 deny-list).
 *
 * @property CarbonImmutable|null $created_at
 * @property int $version
 */
class AnaesthesiaRecordAmendment extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<AnaesthesiaRecordAmendmentFactory> */
    use HasFactory;

    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'thr_anaesthesia_record_amendments';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'facility_id',
        'anaesthesia_record_id',
        'sequence',
        'amendment_text',
        'reason',
        'created_by_user_id',
        'created_at',
        'version',
    ];

    protected static function newFactory(): AnaesthesiaRecordAmendmentFactory
    {
        return AnaesthesiaRecordAmendmentFactory::new();
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
