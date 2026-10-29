<?php

declare(strict_types=1);

use App\Domains\Organization\Domain\Model\Facility;
use App\Domains\Organization\Domain\Model\Organization;
use App\Models\Tenant;
use App\Models\User;

require_once __DIR__.'/security.php';

if (! function_exists('reportingFixture')) {
    /**
     * @param  list<string>|null  $permissions
     * @return array{tenant: Tenant, organization: Organization, facility: Facility, user: User, role_id: string}
     */
    function reportingFixture(?array $permissions = null): array
    {
        $permissions ??= [
            'reporting.access',
            'reporting.dashboard.view',
            'reporting.report.run',
            'reporting.report.define',
            'reporting.report.schedule',
            'reporting.selfservice.query',
            'reporting.mart.curate',
            'reporting.statutory.build',
            'reporting.statutory.review',
            'reporting.statutory.submit',
            'reporting.metric.manage',
            'reporting.projection.manage',
            'reporting.admin.manage',
        ];

        /** @var array{tenant: Tenant, organization: Organization, facility: Facility, user: User, role_id: string} $fixture */
        $fixture = makeTenantWithUser($permissions);
        test()->actingAs($fixture['user']);

        return $fixture;
    }
}
