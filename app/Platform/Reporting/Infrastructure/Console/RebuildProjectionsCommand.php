<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Infrastructure\Console;

use App\Platform\Reporting\Application\Command\RebuildProjectionCommand;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class RebuildProjectionsCommand extends Command
{
    protected $signature = 'reporting:rebuild-projections
                            {--tenant= : Specific tenant UUID to rebuild for}
                            {--stream= : Specific stream to rebuild (encounters, revenue, bed_occupancy, lab_tat)}
                            {--facility= : Specific facility UUID}';

    protected $description = 'Rebuild projection read models deterministically from domain sources';

    public function handle(CommandBus $bus): int
    {
        $tenantId = $this->option('tenant');
        $stream = $this->option('stream');
        $facilityId = $this->option('facility');

        $tenantIds = $tenantId !== null
            ? [(string) $tenantId]
            : (DB::getSchemaBuilder()->hasTable('org_tenants')
                ? DB::table('org_tenants')->pluck('id')->map(fn ($id) => (string) $id)->all()
                : []);

        if (empty($tenantIds)) {
            $this->warn('No tenants found or specified.');

            return self::SUCCESS;
        }

        $streams = $stream !== null
            ? [(string) $stream]
            : ['encounters', 'revenue', 'bed_occupancy', 'lab_tat'];

        foreach ($tenantIds as $tId) {
            $this->info("Rebuilding projections for tenant {$tId}...");

            TenantContextScope::run($tId, $facilityId !== null ? (string) $facilityId : null, function () use ($bus, $tId, $streams, $facilityId): void {
                foreach ($streams as $s) {
                    $this->line("  -> Stream: {$s}");
                    $bus->dispatch(new RebuildProjectionCommand(
                        tenantId: $tId,
                        streamName: $s,
                        facilityId: $facilityId !== null ? (string) $facilityId : null,
                    ));
                }
            });
        }

        $this->info('Projection rebuild complete.');

        return self::SUCCESS;
    }
}
