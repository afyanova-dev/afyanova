<?php

declare(strict_types=1);

namespace App\Platform\Integration\Domain\Model;

use App\Platform\Integration\Domain\Enum\DispatchStatus;
use App\Platform\Integration\Domain\Enum\InboundMessageStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $connector_id
 * @property string $external_ref
 * @property string $correlation_id
 * @property string|null $causation_id
 * @property bool $signature_valid
 * @property array<string, mixed>|null $raw_payload
 * @property string|null $translated_command_type
 * @property array<string, mixed>|null $translated_payload
 * @property DispatchStatus $dispatch_status
 * @property string|null $domain_outcome_reason
 * @property InboundMessageStatus $status
 * @property string|null $dead_letter_reason
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable|null $dispatched_at
 */
final class InboundMessage extends Model
{
    use HasUuids;

    protected $table = 'int_inbound_messages';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'signature_valid' => 'boolean',
            'raw_payload' => 'array',
            'translated_payload' => 'array',
            'dispatch_status' => DispatchStatus::class,
            'status' => InboundMessageStatus::class,
            'received_at' => 'immutable_datetime',
            'dispatched_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<IntegrationConnector, $this>
     */
    public function connector(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnector::class, 'connector_id');
    }

    public function markAuthenticated(): void
    {
        $this->signature_valid = true;
        $this->status = InboundMessageStatus::AUTHENTICATED;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function markTranslated(string $commandType, array $payload): void
    {
        $this->translated_command_type = $commandType;
        $this->translated_payload = $payload;
        $this->status = InboundMessageStatus::TRANSLATED;
    }

    public function markDispatched(): void
    {
        $this->status = InboundMessageStatus::DISPATCHED;
        $this->dispatch_status = DispatchStatus::DISPATCHED;
        $this->dispatched_at = CarbonImmutable::now();
    }

    public function markAck(?string $outcomeReason = null): void
    {
        $this->status = InboundMessageStatus::ACK;
        $this->dispatch_status = DispatchStatus::COMMITTED;
        $this->domain_outcome_reason = $outcomeReason;
    }

    public function markRejected(string $reason): void
    {
        $this->status = InboundMessageStatus::REJECTED;
        $this->dispatch_status = DispatchStatus::REJECTED;
        $this->domain_outcome_reason = $reason;
    }

    public function deadLetter(string $reason): void
    {
        $this->status = InboundMessageStatus::DEAD_LETTER;
        $this->dispatch_status = DispatchStatus::FAILED;
        $this->dead_letter_reason = $reason;
    }

    public function canReplay(): bool
    {
        return $this->status === InboundMessageStatus::DEAD_LETTER || $this->status === InboundMessageStatus::REJECTED;
    }
}
