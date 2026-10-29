<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\Model;

use App\Domains\HR\Contracts\Event\LeaveLedgerEntryPosted;
use App\Domains\HR\Domain\ValueObject\LeaveLedgerKind;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Append-only leave ledger entry aggregate root (25-hr-and-workforce-domain.md §6, §7.9; ADR-206).
 *
 * @domain      HR
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $staff_id
 * @property string $leave_type_id
 * @property LeaveLedgerKind $kind
 * @property float $delta
 * @property string|null $source_ref
 * @property CarbonImmutable $effective_date
 * @property string|null $reason
 * @property string $posted_by
 * @property int $version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property Staff|null $staff
 * @property LeaveType|null $leaveType
 */
final class LeaveLedgerEntry extends AggregateRoot
{
    protected $table = 'hr_leave_ledger_entries';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'staff_id',
        'leave_type_id',
        'kind',
        'delta',
        'source_ref',
        'effective_date',
        'reason',
        'posted_by',
        'version',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'kind' => LeaveLedgerKind::class,
        'delta' => 'float',
        'effective_date' => 'immutable_date',
        'version' => 'integer',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    public static function post(
        string $tenantId,
        string $staffId,
        string $leaveTypeId,
        LeaveLedgerKind $kind,
        float $delta,
        CarbonImmutable $effectiveDate,
        string $postedBy,
        ?string $sourceRef = null,
        ?string $reason = null,
        ?string $ledgerEntryId = null,
    ): self {
        $id = $ledgerEntryId ?? (string) Str::uuid();

        $entry = new self([
            'tenant_id' => $tenantId,
            'staff_id' => $staffId,
            'leave_type_id' => $leaveTypeId,
            'kind' => $kind,
            'delta' => $delta,
            'source_ref' => $sourceRef,
            'effective_date' => $effectiveDate,
            'reason' => $reason,
            'posted_by' => $postedBy,
            'version' => 0,
        ]);

        $entry->id = $id;

        $entry->recordThat(new LeaveLedgerEntryPosted(
            eventId: (string) Str::uuid(),
            tenantId: $tenantId,
            ledgerEntryId: $id,
            staffId: $staffId,
            leaveTypeId: $leaveTypeId,
            kind: $kind->value,
            delta: $delta,
            sourceRef: $sourceRef,
            effectiveDate: $effectiveDate->toDateString(),
            postedBy: $postedBy,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
        ));

        return $entry;
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'staff_id');
    }

    /**
     * @return BelongsTo<LeaveType, $this>
     */
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }
}
