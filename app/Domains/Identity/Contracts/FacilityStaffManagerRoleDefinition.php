<?php

declare(strict_types=1);

namespace App\Domains\Identity\Contracts;

final class FacilityStaffManagerRoleDefinition
{
    public const KEY = 'facility_staff_manager';

    public const PERMISSIONS = [
        'hr.access',
        'hr.person.manage',
        'hr.staff.read',
        'hr.staff.manage',
        'hr.employment.manage',
        'hr.assignment.manage',
        'hr.cadre_position.manage',
        'hr.staff_user_link.manage',
    ];
}
