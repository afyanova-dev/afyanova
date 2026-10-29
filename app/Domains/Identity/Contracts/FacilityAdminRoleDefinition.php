<?php

declare(strict_types=1);

namespace App\Domains\Identity\Contracts;

final class FacilityAdminRoleDefinition
{
    public const KEY = 'facility_admin';

    public const PERMISSIONS = [
        'admin.workspace.access',
        'identity.access',
        'identity.users.read',
        'identity.users.manage',
        'identity.roles.read',
        'identity.roles.manage',
        'identity.assignments.read',
        'identity.assignments.manage',
        'organization.access',
        'organization.facilities.read',
        'organization.structure.manage',
        'organization.capabilities.manage',
        'inventory.access',
        'inventory.locations.manage',
        'inventory.location_types.manage',
        'inventory.receipts.create',
        // Phase 8 — moving stock between the facility's own locations (main
        // store → pharmacy counter) is the facility admin's day-to-day job.
        'inventory.transfers.create',
        // Phase 8 — physically counting stock at a facility location, and
        // recording what was found, is the facility admin's control job.
        'inventory.counts.manage',
        // Phase 8 — a count's differences become pending corrections that a
        // second person approves. The facility admin raises and approves them
        // (never their own), so the two-person rule is what matters, not the
        // role.
        'inventory.adjustments.create',
        'inventory.adjustments.approve',
        // Phase 7 — the facility admin is the receiving actor, so they must be
        // able to read the open purchase orders a receipt is validated against.
        'procurement.access',
        // Proc.4 / Shape B — in a small private hospital the facility admin IS
        // the procurement officer: they register suppliers, raise + approve a
        // department's requisition, and create / approve / issue purchase
        // orders. The different-actor guards on requisitions (§24) and, above
        // the configured money limit, on purchase orders (§45 / §228) still
        // apply — these keys do not remove them.
        'procurement.suppliers.manage',
        'procurement.requisitions.create',
        'procurement.requisitions.approve',
        'procurement.orders.create',
        'procurement.orders.approve',
        'procurement.orders.issue',
        'procurement.orders.amend',
        'procurement.emergency.manage',
        // Shape B — the Facility Admin sets the approval limit for the
        // facilities they are assigned to (the page + the command both check
        // the key per facility, so a Facility Admin cannot edit another
        // facility's limit).
        'procurement.approval_limits.manage',
        'queue.access',
        'queue.ticket.view',
        'queue.ticket.place',
        'queue.ticket.call',
        'queue.ticket.service',
        'queue.ticket.hold',
        'queue.ticket.transfer',
        'queue.ticket.skip',
        'queue.movement.view',
        'queue.movement.record',
        'queue.definition.manage',
    ];
}
