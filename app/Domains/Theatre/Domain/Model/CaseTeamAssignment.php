<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain\Model;

use App\Domains\Theatre\Database\Factories\CaseTeamAssignmentFactory;
use App\Shared\Concurrency\HasVersionColumn;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A staff assignment to a surgical case (17-theatre-domain.md §41-45;
 * ADR-150 D-C/D-F).
 *
 * Plain Eloquent child model — NOT an AggregateRoot (no team-assignment
 * event is named anywhere in the domain doc — the Room precedent from
 * ADR-148 D-A). Written directly by AssignTeamMemberHandler /
 * ReplaceTeamMemberHandler. `replaced_at` (nullable) satisfies §45's "Staff
 * Assignment History" via append-and-mark-replaced, never a hard delete.
 *
 * @property CarbonImmutable|null $assigned_at
 * @property CarbonImmutable|null $replaced_at
 */
class CaseTeamAssignment extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<CaseTeamAssignmentFactory> */
    use HasFactory;

    use HasUuids;
    use HasVersionColumn;

    protected $table = 'thr_case_team_assignments';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'facility_id',
        'case_id',
        'staff_user_id',
        'role',
        'assigned_at',
        'replaced_at',
        'version',
    ];

    protected static function newFactory(): CaseTeamAssignmentFactory
    {
        return CaseTeamAssignmentFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'assigned_at' => 'immutable_datetime',
            'replaced_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }
}
