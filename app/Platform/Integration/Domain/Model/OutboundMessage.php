<?php

declare(strict_types=1);

namespace App\Platform\Integration\Domain\Model;

use App\Platform\Integration\Domain\Enum\OutboundMessageStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $connector_id
 * @property string $triggered_by
 * @property string $correlation_id
 * @property string|null $causation_id
 * @property string|null $recipient_ref
 * @property array<string, mixed> $payload
 * @property int $attempts
 * @property int $max_attempts
 * @property CarbonImmutable|null $next_retry_at
 * @property string|null $provider_ref
 * @property array<string, mixed>|null $last_response
 * @property OutboundMessageStatus $status
 * @property string|null $dead_letter_reason
 */
final class OutboundMessage extends Model
{
    use HasUuids;

    protected $table = 'int_outbound_messages';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'last_response' => 'array',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'next_retry_at' => 'immutable_datetime',
            'status' => OutboundMessageStatus::class,
        ];
    }

    /**
     * @return BelongsTo<IntegrationConnector, $this>
     */
    public function connector(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnector::class, 'connector_id');
    }

    public function markSending(): void
    {
        $this->status = OutboundMessageStatus::SENDING;
        $this->attempts++;
    }

    /**
     * @param  array<string, mixed>|null  $response
     */
    public function markSent(?string $providerRef, ?array $response = null): void
    {
        $this->status = OutboundMessageStatus::SENT;
        $this->provider_ref = $providerRef;
        $this->last_response = $response;
    }

    /**
     * @param  array<string, mixed>|null  $response
     */
    public function markAck(?array $response = null): void
    {
        $this->status = OutboundMessageStatus::ACK;
        if ($response !== null) {
            $this->last_response = $response;
        }
    }

    /**
     * @param  array<string, mixed>|null  $response
     */
    public function recordFailure(string $reason, ?array $response = null): void
    {
        $this->last_response = $response;

        if ($this->attempts >= $this->max_attempts) {
            $this->status = OutboundMessageStatus::DEAD_LETTER;
            $this->dead_letter_reason = $reason;
            $this->next_retry_at = null;
        } else {
            $this->status = OutboundMessageStatus::FAILED;
            // Exponential backoff: 2^(attempts-1) * 30 seconds
            $backoffSeconds = (int) (pow(2, max(0, $this->attempts - 1)) * 30);
            $this->next_retry_at = CarbonImmutable::now()->addSeconds($backoffSeconds);
        }
    }

    public function deadLetter(string $reason): void
    {
        $this->status = OutboundMessageStatus::DEAD_LETTER;
        $this->dead_letter_reason = $reason;
        $this->next_retry_at = null;
    }
}
