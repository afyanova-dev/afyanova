<?php

declare(strict_types=1);

namespace App\Platform\Integration\Domain\Model;

use App\Platform\Integration\Domain\Enum\AuthType;
use App\Platform\Integration\Domain\Enum\ConnectorDirection;
use App\Platform\Integration\Domain\Enum\ConnectorFamily;
use App\Platform\Integration\Domain\Enum\ConnectorStatus;
use App\Platform\Integration\Domain\Enum\PayloadSensitivity;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $key
 * @property ConnectorFamily $family
 * @property ConnectorDirection $direction
 * @property string|null $base_url
 * @property AuthType $auth_type
 * @property PayloadSensitivity $payload_sensitivity
 * @property int $retention_days
 * @property int $rate_limit_per_minute
 * @property ConnectorStatus $status
 * @property array<string, mixed>|null $endpoint_config
 */
final class IntegrationConnector extends Model
{
    use HasUuids;

    protected $table = 'int_connectors';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'family' => ConnectorFamily::class,
            'direction' => ConnectorDirection::class,
            'endpoint_config' => 'array',
            'auth_type' => AuthType::class,
            'payload_sensitivity' => PayloadSensitivity::class,
            'status' => ConnectorStatus::class,
            'retention_days' => 'integer',
            'rate_limit_per_minute' => 'integer',
        ];
    }

    /**
     * @return HasMany<IntegrationCredential, $this>
     */
    public function credentials(): HasMany
    {
        return $this->hasMany(IntegrationCredential::class, 'connector_id');
    }

    /**
     * @return HasMany<WebhookEndpoint, $this>
     */
    public function webhookEndpoints(): HasMany
    {
        return $this->hasMany(WebhookEndpoint::class, 'connector_id');
    }

    public function getBaseUrlAttribute(): ?string
    {
        /** @var array<string, mixed>|null $config */
        $config = $this->endpoint_config;

        return is_array($config) && isset($config['base_url']) && is_string($config['base_url'])
            ? $config['base_url']
            : null;
    }

    public function isOperable(): bool
    {
        return $this->status->isOperable();
    }

    public function enable(): void
    {
        $this->status = ConnectorStatus::ENABLED;
    }

    public function suspend(): void
    {
        $this->status = ConnectorStatus::SUSPENDED;
    }

    public function disable(): void
    {
        $this->status = ConnectorStatus::DISABLED;
    }
}
