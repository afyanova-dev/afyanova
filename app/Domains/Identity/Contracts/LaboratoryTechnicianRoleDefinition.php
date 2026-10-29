<?php

declare(strict_types=1);

namespace App\Domains\Identity\Contracts;

final class LaboratoryTechnicianRoleDefinition
{
    public const KEY = 'laboratory_technician';

    public const PERMISSIONS = [
        'laboratory.access',
        'laboratory.orders.accept',
        'laboratory.specimens.receive',
        'laboratory.results.enter',
        'laboratory.results.verify',
        // Shape B — the lab is the department that runs out of reagents and
        // kits first. A technician may raise (and read back) a purchase
        // requisition for the department; they can neither approve it (§24 —
        // the approver is a genuinely different actor) nor buy anything.
        'procurement.access',
        'procurement.requisitions.create',
    ];
}
