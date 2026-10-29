<?php

declare(strict_types=1);

namespace App\Platform\Integration\Domain\Model;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $connector_id
 * @property string $path_slug
 * @property string|null $secret_ref
 * @property list<string>|null $allowed_ips
 * @property bool $active
 */
final class WebhookEndpoint extends Model
{
    use HasUuids;

    protected $table = 'int_webhook_endpoints';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'allowed_ips' => 'array',
            'active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<IntegrationConnector, $this>
     */
    public function connector(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnector::class, 'connector_id');
    }
}
