<?php

declare(strict_types=1);

namespace App\Domains\Identity\Console;

use App\Domains\Identity\Domain\Model\StaffIdentity;
use App\Shared\Audit\AuditLogger;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** PHASE 3 ops entry point for the Identity side of staff identity (docs/00-foundation/03-identity-rbac.md §4–§6). */
final class ManageStaffIdentityCommand extends Command
{
    protected $signature = 'afyanova:identity:staff
        {--tenant= : tenant uuid}
        {--user= : user uuid}
        {--staff-number= : optional staff number}
        {--not-staff : mark as a non-staff platform identity}';

    protected $description = 'Link a user to a staff identity (or mark it non-staff).';

    public function handle(AuditLogger $audit): int
    {
        $tenantId = (string) $this->option('tenant');
        $userId = (string) $this->option('user');
        if ($tenantId === '' || $userId === '') {
            $this->error('--tenant and --user are required.');

            return self::FAILURE;
        }

        return TenantContextScope::run($tenantId, null, function () use ($tenantId, $userId, $audit): int {
            $row = DB::transaction(function () use ($tenantId, $userId, $audit): StaffIdentity {
                $row = StaffIdentity::query()->updateOrCreate(
                    ['user_id' => $userId],
                    [
                        'tenant_id' => $tenantId,
                        'is_staff' => ! $this->option('not-staff'),
                        'staff_number' => $this->option('staff-number') ?: null,
                    ],
                );

                if ($row->wasRecentlyCreated || $row->wasChanged()) {
                    $audit->recordFor(
                        tenantId: $tenantId,
                        action: 'identity.staff_identity.updated',
                        facilityId: $row->facility_id,
                        metadata: ['is_staff' => (bool) $row->is_staff],
                        targetType: 'user',
                        targetId: $userId,
                    );
                }

                return $row;
            });

            $this->info("Staff identity for {$userId}: is_staff=".($row->is_staff ? 'true' : 'false'));

            return self::SUCCESS;
        });
    }
}
