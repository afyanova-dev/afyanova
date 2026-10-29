<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Model;

use App\Domains\Procurement\Domain\Money;
use App\Shared\Domain\AggregateRoot;

/**
 * A single-actor approval limit for one scope (Shape B; 19-procurement-domain.md
 * §24, §45, §228).
 *
 * A purchase order whose total is at or below the effective limit may be
 * approved AND issued by one person; above it, the different-actor guards
 * apply unchanged. Two kinds of row share the table:
 *
 *   - `department_ref = NULL` — the facility-wide limit (one per facility);
 *   - `department_ref = <id>` — a department override (one per facility +
 *     department), matched against the requisition's `requesting_department_ref`
 *     (opaque, no FK — §224).
 *
 * A scope with NO row falls back to the facility-type default and finally to
 * `0` (off) — see `App\Domains\Procurement\Application\ApprovalLimitResolver`.
 * Authored only through `procurement:configure-approval-limit`.
 *
 * @domain      Procurement
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @facility    required
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $facility_id
 * @property string|null $department_ref
 * @property string $self_approval_limit
 * @property string $currency
 * @property int $version
 */
final class ApprovalPolicy extends AggregateRoot
{
    public const CURRENCY_TZS = 'TZS';

    protected $table = 'pcr_approval_policies';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id', 'department_ref',
        'self_approval_limit', 'currency', 'version',
    ];

    public static function define(
        string $facilityId,
        ?string $departmentRef,
        string $limit,
        string $currency = self::CURRENCY_TZS,
    ): self {
        return new self([
            'facility_id' => $facilityId,
            'department_ref' => $departmentRef,
            'self_approval_limit' => Money::of($limit),
            'currency' => strtoupper(trim($currency)),
            'version' => 1,
        ]);
    }

    public function reviseLimit(string $limit): void
    {
        $this->self_approval_limit = Money::of($limit);
        $this->version = (int) $this->version + 1; // always dirties the row (T.2 lesson)
    }

    public function isDepartmentScoped(): bool
    {
        return $this->department_ref !== null;
    }
}
