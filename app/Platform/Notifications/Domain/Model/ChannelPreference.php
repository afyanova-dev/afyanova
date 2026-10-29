<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Domain\Model;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $tenant_id
 * @property RecipientType $subject_type
 * @property string $subject_id
 * @property NotificationChannel|null $preferred_channel
 * @property array<string> $muted_categories
 * @property string|null $quiet_hours_start
 * @property string|null $quiet_hours_end
 * @property string $preferred_language
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class ChannelPreference extends Model
{
    use HasUuids;

    protected $table = 'notif_channel_preferences';

    protected $fillable = [
        'id',
        'tenant_id',
        'subject_type',
        'subject_id',
        'preferred_channel',
        'muted_categories',
        'quiet_hours_start',
        'quiet_hours_end',
        'preferred_language',
    ];

    protected $casts = [
        'subject_type' => RecipientType::class,
        'preferred_channel' => NotificationChannel::class,
        'muted_categories' => 'array',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    public function isCategoryMuted(NotificationCategory $category): bool
    {
        return in_array($category->value, $this->muted_categories, true);
    }
}
