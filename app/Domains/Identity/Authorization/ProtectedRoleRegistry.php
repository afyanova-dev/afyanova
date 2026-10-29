<?php

declare(strict_types=1);

namespace App\Domains\Identity\Authorization;

use App\Domains\Identity\Contracts\ClinicianRoleDefinition;
use App\Domains\Identity\Contracts\LaboratoryTechnicianRoleDefinition;
use App\Domains\Identity\Contracts\TenantAdminRoleDefinition;

final class ProtectedRoleRegistry
{
    private const KEYS = [
        'facility_admin',
        'receptionist',
        'cashier',
        'billing_admin',
        'pharmacist',
        LaboratoryTechnicianRoleDefinition::KEY,
        ClinicianRoleDefinition::KEY,
        'nurse',
        'facility_staff_manager',
        TenantAdminRoleDefinition::KEY,
    ];

    public static function contains(string $key): bool
    {
        return in_array($key, self::KEYS, true);
    }
}
