<?php

declare(strict_types=1);

namespace App\Platform\Documents\Domain\Model;

use App\Platform\Documents\Domain\Enum\RetentionImposedBy;
use App\Shared\Domain\HasUuid;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * RetentionRequirement entity.
 *
 * @authority custodial (PLATFORM SERVICE)
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $document_id
 * @property RetentionImposedBy $imposed_by
 * @property CarbonImmutable|null $retain_until
 * @property bool $indefinite
 * @property string $reason
 * @property string $actor_id
 * @property CarbonImmutable|null $released_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class RetentionRequirement extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'doc_retention_requirements';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'document_id',
        'imposed_by',
        'retain_until',
        'indefinite',
        'reason',
        'actor_id',
        'released_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'imposed_by' => RetentionImposedBy::class,
        'retain_until' => 'immutable_datetime',
        'indefinite' => 'boolean',
        'released_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    public static function createRequirement(
        string $tenantId,
        string $documentId,
        RetentionImposedBy $imposedBy,
        string $reason,
        string $actorId,
        ?CarbonImmutable $retainUntil = null,
        bool $indefinite = false,
    ): self {
        $req = new self;
        $req->tenant_id = $tenantId;
        $req->document_id = $documentId;
        $req->imposed_by = $imposedBy;
        $req->reason = $reason;
        $req->actor_id = $actorId;
        $req->retain_until = $retainUntil;
        $req->indefinite = $indefinite;

        return $req;
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function isActive(?CarbonImmutable $asOf = null): bool
    {
        if ($this->released_at !== null) {
            return false;
        }

        if ($this->indefinite) {
            return true;
        }

        if ($this->retain_until === null) {
            return false;
        }

        $time = $asOf ?? CarbonImmutable::now();

        return $this->retain_until->isAfter($time);
    }

    public function release(CarbonImmutable $releasedAt): void
    {
        $this->released_at = $releasedAt;
    }
}
