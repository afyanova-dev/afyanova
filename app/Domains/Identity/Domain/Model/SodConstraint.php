<?php

declare(strict_types=1);

namespace App\Domains\Identity\Domain\Model;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A separation-of-duties constraint (docs/00-foundation/03-identity-rbac.md
 * §20–§21). DATA ONLY in PHASE 3 — the command bus enforces dynamic SoD in
 * PHASE 4.
 */
class SodConstraint extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'idn_sod_constraints';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'facility_id', 'name', 'kind', 'a', 'b'];
}
