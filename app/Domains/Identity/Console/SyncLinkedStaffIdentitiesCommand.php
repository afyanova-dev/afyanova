<?php

declare(strict_types=1);

namespace App\Domains\Identity\Console;

use App\Domains\Identity\Contracts\IdentityApi;
use App\Domains\Identity\Domain\Model\StaffIdentity;
use App\Shared\Audit\AuditLogger;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class SyncLinkedStaffIdentitiesCommand extends Command
{
    protected $signature = 'afyanova:identity:sync-linked-staff
        {--tenant= : tenant uuid}
        {--apply : persist the synchronization; otherwise perform a dry run}';

    protected $description = 'Synchronize Identity records for HR staff linked to user accounts.';

    public function handle(IdentityApi $identity, AuditLogger $audit): int
    {
        $tenantId = (string) $this->option('tenant');

        if ($tenantId === '') {
            $this->error('--tenant is required.');

            return self::FAILURE;
        }

        return TenantContextScope::run($tenantId, null, function () use ($tenantId, $identity, $audit): int {
            $linkedStaff = DB::table('hr_staff_user_links as links')
                ->join('hr_staff as staff', 'staff.id', '=', 'links.staff_id')
                ->where('links.tenant_id', $tenantId)
                ->orderBy('links.user_id')
                ->get([
                    'links.user_id',
                    'links.staff_id',
                    'staff.staff_number',
                ]);
            $changesRequired = 0;
            $alreadySynchronized = 0;
            $applyChanges = (bool) $this->option('apply');

            foreach ($linkedStaff as $linked) {
                $staffIdentity = StaffIdentity::query()
                    ->where('user_id', $linked->user_id)
                    ->first();

                $isSynchronized = $staffIdentity !== null
                    && $staffIdentity->tenant_id === $tenantId
                    && $staffIdentity->is_staff
                    && $staffIdentity->staff_ref === $linked->staff_id
                    && $staffIdentity->staff_number === $linked->staff_number;

                if ($isSynchronized) {
                    $alreadySynchronized++;

                    continue;
                }

                $changesRequired++;

                if (! $applyChanges) {
                    continue;
                }

                $identity->linkStaffIdentity(
                    tenantId: $tenantId,
                    userId: $linked->user_id,
                    staffRef: $linked->staff_id,
                    staffNumber: $linked->staff_number,
                );

                $updatedIdentity = StaffIdentity::query()
                    ->where('user_id', $linked->user_id)
                    ->firstOrFail();

                $audit->recordFor(
                    tenantId: $tenantId,
                    action: 'identity.staff_identity.updated',
                    facilityId: $updatedIdentity->facility_id,
                    metadata: [
                        'is_staff' => true,
                        'staff_reference_synced' => true,
                    ],
                    targetType: 'user',
                    targetId: $linked->user_id,
                );
            }

            $mode = $applyChanges ? 'applied' : 'dry run';
            $this->info(
                "Tenant {$tenantId}: {$linkedStaff->count()} linked staff accounts; ".
                "{$changesRequired} changes {$mode}; {$alreadySynchronized} already synchronized."
            );

            return self::SUCCESS;
        });
    }
}
