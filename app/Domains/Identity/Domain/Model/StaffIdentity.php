<?php

declare(strict_types=1);

namespace App\Domains\Identity\Domain\Model;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * The Identity side of "staff identity" (docs/00-foundation/03-identity-rbac.md
 * §4–§6). A non-staff user (auditor, inspector, contractor) has NO row here but
 * still holds access assignments. `staff_ref` links to the HR Staff record
 * (PHASE 11).
 */
class StaffIdentity extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'idn_staff_identities';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'facility_id', 'user_id', 'is_staff', 'staff_number', 'staff_ref'];

    protected $casts = ['is_staff' => 'boolean'];
}
