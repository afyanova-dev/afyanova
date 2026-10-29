<?php

declare(strict_types=1);

namespace App\Platform\Integration\Domain\Model;

use App\Platform\Integration\Domain\Enum\CredentialAlgorithm;
use App\Platform\Integration\Domain\Enum\CredentialStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $connector_id
 * @property string $key_identifier
 * @property string $secret_ref
 * @property CredentialAlgorithm $algorithm
 * @property CredentialStatus $status
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $last_rotated_at
 * @property IntegrationConnector|null $connector
 */
final class IntegrationCredential extends Model
{
    use HasUuids;

    protected $table = 'int_credentials';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'algorithm' => CredentialAlgorithm::class,
            'status' => CredentialStatus::class,
            'expires_at' => 'immutable_datetime',
            'last_rotated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<IntegrationConnector, $this>
     */
    public function connector(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnector::class, 'connector_id');
    }

    public function isUsable(): bool
    {
        if (! $this->status->isUsable()) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    public function rotate(string $newSecretRef): void
    {
        $this->secret_ref = $newSecretRef;
        $this->last_rotated_at = CarbonImmutable::now();
        $this->status = CredentialStatus::ACTIVE;
    }

    public function revoke(): void
    {
        $this->status = CredentialStatus::REVOKED;
    }
}
