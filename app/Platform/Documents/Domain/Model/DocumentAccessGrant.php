<?php

declare(strict_types=1);

namespace App\Platform\Documents\Domain\Model;

use App\Shared\Domain\HasUuid;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DocumentAccessGrant entity.
 *
 * @authority custodial (PLATFORM SERVICE)
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $document_id
 * @property string $principal_id
 * @property string $scope
 * @property CarbonImmutable $expires_at
 * @property string $granted_by
 * @property CarbonImmutable $granted_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class DocumentAccessGrant extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'doc_access_grants';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'document_id',
        'principal_id',
        'scope',
        'expires_at',
        'granted_by',
        'granted_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'expires_at' => 'immutable_datetime',
        'granted_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    public static function createGrant(
        string $tenantId,
        string $documentId,
        string $principalId,
        string $scope,
        CarbonImmutable $expiresAt,
        string $grantedBy,
        CarbonImmutable $grantedAt,
    ): self {
        $grant = new self;
        $grant->tenant_id = $tenantId;
        $grant->document_id = $documentId;
        $grant->principal_id = $principalId;
        $grant->scope = $scope;
        $grant->expires_at = $expiresAt;
        $grant->granted_by = $grantedBy;
        $grant->granted_at = $grantedAt;

        return $grant;
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function isExpired(?CarbonImmutable $asOf = null): bool
    {
        $time = $asOf ?? CarbonImmutable::now();

        return $this->expires_at->isBefore($time);
    }

    public function hasScope(string $scope): bool
    {
        return $this->scope === '*' || $this->scope === $scope;
    }
}
