<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Domain\Model;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $key
 * @property string $name
 * @property NotificationCategory $category
 * @property array<string> $channels
 * @property array<string, mixed> $content
 * @property bool $clinical_safe
 * @property bool $consent_required
 * @property int $version
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class NotificationTemplate extends Model
{
    use HasUuids;

    protected $table = 'notif_templates';

    protected $fillable = [
        'id',
        'tenant_id',
        'key',
        'name',
        'category',
        'channels',
        'content',
        'clinical_safe',
        'consent_required',
        'version',
        'is_active',
    ];

    protected $casts = [
        'category' => NotificationCategory::class,
        'channels' => 'array',
        'content' => 'array',
        'clinical_safe' => 'boolean',
        'consent_required' => 'boolean',
        'version' => 'integer',
        'is_active' => 'boolean',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];
}
