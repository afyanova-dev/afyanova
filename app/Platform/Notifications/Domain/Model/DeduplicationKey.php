<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Domain\Model;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $dedupe_key
 * @property string $request_id
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class DeduplicationKey extends Model
{
    use HasUuids;

    protected $table = 'notif_deduplication_keys';

    protected $fillable = [
        'id',
        'tenant_id',
        'dedupe_key',
        'request_id',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];
}
