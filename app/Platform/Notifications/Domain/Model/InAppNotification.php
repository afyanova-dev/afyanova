<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Domain\Model;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property string|null $notification_request_id
 * @property string $title
 * @property string $body
 * @property string|null $action_url
 * @property CarbonImmutable|null $read_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class InAppNotification extends Model
{
    use HasUuids;

    protected $table = 'notif_in_app_notifications';

    protected $fillable = [
        'id',
        'tenant_id',
        'user_id',
        'notification_request_id',
        'title',
        'body',
        'action_url',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    public function markAsRead(): void
    {
        if ($this->read_at === null) {
            $this->read_at = CarbonImmutable::now();
            $this->save();
        }
    }
}
