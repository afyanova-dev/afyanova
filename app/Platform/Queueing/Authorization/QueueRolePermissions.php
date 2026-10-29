<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Authorization;

/**
 * The queue permission keys each demo role is supposed to hold.
 *
 * Single source of truth shared by `DatabaseSeeder` and the
 * `queue:sync-role-permissions` backfill command, so fresh seeds and
 * existing databases cannot drift apart. Keys must exist in
 * `Authorization/permissions.php`; callers skip unknown keys via
 * `PermissionRegistry::has()`.
 */
final class QueueRolePermissions
{
    /**
     * @return array<string, list<string>> role key => permission keys
     */
    public static function defaults(): array
    {
        $frontline = [
            'queue.access',
            'queue.ticket.view',
            'queue.ticket.place',
            'queue.ticket.call',
            'queue.ticket.service',
            'queue.ticket.hold',
            'queue.ticket.transfer',
            'queue.ticket.skip',
        ];

        return [
            // Front desk: full worker set, no queue definition management.
            'receptionist' => $frontline,
            // Triage nurses: same worker set as the front desk.
            'nurse' => $frontline,
            // Facility administrators: everything, including queue management.
            'facility_admin' => array_merge($frontline, [
                'queue.movement.view',
                'queue.movement.record',
                'queue.definition.manage',
            ]),
            // Doctors work queues but do not place, hold, or skip tickets.
            'doctor' => [
                'queue.access',
                'queue.ticket.view',
                'queue.ticket.call',
                'queue.ticket.service',
                'queue.ticket.transfer',
            ],
            // Cashiers work the billing-clearance queue only.
            'cashier' => [
                'queue.access',
                'queue.ticket.view',
                'queue.ticket.call',
                'queue.ticket.service',
            ],
        ];
    }
}
