<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Domain\Model;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $notification_request_id
 * @property NotificationChannel $channel
 * @property string $provider
 * @property string|null $provider_message_id
 * @property string|null $masked_destination
 * @property DeliveryAttemptStatus $status
 * @property string|null $error_code
 * @property string|null $error_message
 * @property CarbonImmutable $attempted_at
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class DeliveryAttempt extends Model
{
    use HasUuids;

    protected $table = 'notif_delivery_attempts';

    protected $fillable = [
        'id',
        'tenant_id',
        'notification_request_id',
        'channel',
        'provider',
        'provider_message_id',
        'masked_destination',
        'status',
        'error_code',
        'error_message',
        'attempted_at',
        'delivered_at',
    ];

    protected $casts = [
        'channel' => NotificationChannel::class,
        'status' => DeliveryAttemptStatus::class,
        'attempted_at' => 'immutable_datetime',
        'delivered_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<NotificationRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(NotificationRequest::class, 'notification_request_id');
    }
}
