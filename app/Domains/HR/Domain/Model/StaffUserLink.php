<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\Model;

use App\Domains\HR\Contracts\Event\StaffLinkedToUser;
use App\Domains\HR\Contracts\Event\StaffUnlinkedFromUser;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Staff-User Link aggregate (25-hr-and-workforce-domain.md §2, §6, §7; ADR-206).
 *
 * Optional 1:1 mapping between Staff and Identity.User.
 * Invariant 7.3: Unlinking never deletes either Staff or User.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $staff_id
 * @property string $user_id
 * @property CarbonImmutable $linked_at
 * @property string $linked_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class StaffUserLink extends AggregateRoot
{
    protected $table = 'hr_staff_user_links';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'staff_id',
        'user_id',
        'linked_at',
        'linked_by',
        'version',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'linked_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    public static function create(
        string $tenantId,
        string $staffId,
        string $userId,
        string $linkedBy,
        ?string $linkId = null,
    ): self {
        $id = $linkId ?? (string) Str::uuid();
        $now = CarbonImmutable::now();

        $link = new self([
            'tenant_id' => $tenantId,
            'staff_id' => $staffId,
            'user_id' => $userId,
            'linked_at' => $now,
            'linked_by' => $linkedBy,
        ]);

        $link->id = $id;

        $link->recordThat(new StaffLinkedToUser(
            eventId: (string) Str::uuid(),
            tenantId: $tenantId,
            linkId: $id,
            staffId: $staffId,
            userId: $userId,
            linkedAt: $now->toIso8601String(),
            linkedBy: $linkedBy,
            occurredAt: $now->toIso8601String(),
        ));

        return $link;
    }

    public function recordUnlink(string $unlinkedBy, ?string $reason = null): void
    {
        $this->recordThat(new StaffUnlinkedFromUser(
            eventId: (string) Str::uuid(),
            tenantId: $this->tenant_id,
            linkId: $this->id,
            staffId: $this->staff_id,
            userId: $this->user_id,
            unlinkedAt: CarbonImmutable::now()->toIso8601String(),
            unlinkedBy: $unlinkedBy,
            reason: $reason,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
        ));
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'staff_id');
    }
}
