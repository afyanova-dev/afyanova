<?php

declare(strict_types=1);

namespace App\Platform\Documents\Domain\Model;

use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Consumed capability token tracking for single-use replay protection.
 *
 * @authority custodial (PLATFORM SERVICE)
 *
 * @property string $tenant_id
 * @property string $jti
 * @property CarbonImmutable $consumed_at
 * @property CarbonImmutable $expires_at
 */
final class ConsumedToken extends Model
{
    use BelongsToTenant;

    protected $table = 'doc_consumed_tokens';

    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'jti';

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'jti',
        'consumed_at',
        'expires_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'consumed_at' => 'immutable_datetime',
        'expires_at' => 'immutable_datetime',
    ];

    public static function createRecord(
        string $tenantId,
        string $jti,
        CarbonImmutable $consumedAt,
        CarbonImmutable $expiresAt,
    ): self {
        $token = new self;
        $token->tenant_id = $tenantId;
        $token->jti = $jti;
        $token->consumed_at = $consumedAt;
        $token->expires_at = $expiresAt;

        return $token;
    }
}
