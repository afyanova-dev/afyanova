<?php

declare(strict_types=1);

use App\Domains\Organization\Domain\Model\Facility;
use App\Domains\Organization\Domain\Model\Organization;
use App\Models\Tenant;
use App\Models\User;

require_once __DIR__.'/security.php';

if (! function_exists('notificationsFixture')) {
    /**
     * @param  list<string>|null  $permissions
     * @return array{tenant: Tenant, organization: Organization, facility: Facility, user: User, role_id: string}
     */
    function notificationsFixture(?array $permissions = null): array
    {
        $permissions ??= [
            'notifications.access',
            'notifications.message.send',
            'notifications.message.bulk',
            'notifications.template.manage',
            'notifications.preference.manage',
            'notifications.delivery.view',
        ];

        /** @var array{tenant: Tenant, organization: Organization, facility: Facility, user: User, role_id: string} $fixture */
        $fixture = makeTenantWithUser($permissions);
        test()->actingAs($fixture['user']);

        return $fixture;
    }
}
