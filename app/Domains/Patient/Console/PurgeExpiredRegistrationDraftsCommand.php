<?php

declare(strict_types=1);

namespace App\Domains\Patient\Console;

use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PurgeExpiredRegistrationDraftsCommand extends Command
{
    protected $signature = 'patient:registration-drafts:purge';

    protected $description = 'Permanently delete expired encrypted patient registration drafts.';

    public function handle(): int
    {
        $deleted = 0;

        foreach (DB::table('shared_tenants')->pluck('id') as $tenantId) {
            $deleted += TenantContextScope::run(
                (string) $tenantId,
                null,
                fn (): int => DB::table('pat_registration_drafts')
                    ->where('expires_at', '<=', now())
                    ->delete(),
            );
        }

        $this->info(sprintf('Deleted %d expired patient registration draft(s).', $deleted));

        return self::SUCCESS;
    }
}
