<?php

declare(strict_types=1);

use App\Domains\Organization\Domain\Model\Facility;
use App\Domains\Organization\Domain\Model\Organization;
use App\Models\Tenant;
use App\Models\User;

require_once __DIR__.'/security.php';

if (! function_exists('documentsFixture')) {
    /**
     * @param  list<string>|null  $permissions
     * @return array{tenant: Tenant, organization: Organization, facility: Facility, user: User, role_id: string}
     */
    function documentsFixture(?array $permissions = null): array
    {
        $permissions ??= [
            'documents.access',
            'documents.document.upload',
            'documents.document.generate',
            'documents.document.view',
            'documents.document.download',
            'documents.document.sign',
            'documents.document.redact',
            'documents.document.dispose',
            'documents.template.manage',
            'documents.retention.manage',
            'documents.grant.manage',
        ];

        /** @var array{tenant: Tenant, organization: Organization, facility: Facility, user: User, role_id: string} $fixture */
        $fixture = makeTenantWithUser($permissions);
        test()->actingAs($fixture['user']);

        return $fixture;
    }
}
