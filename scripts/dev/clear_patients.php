<?php

declare(strict_types=1);

/**
 * Tenant-scoped patient clear helper (local dev tooling, not shipped code).
 *
 * RLS is FORCED on tenant tables, so a bare `php artisan patient:clear`
 * sees zero rows. This script establishes the connector tenant context
 * first, then delegates to the tested `patient:clear` command.
 *
 * Usage:
 *   php /tmp/opencode/clear_patients.php <tenant-uuid> [--dry-run]
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require '/home/rajani/Desktop/Projects/afyanova_v4/vendor/autoload.php';

$tenant = $argv[1] ?? null;
if (! $tenant) {
    fwrite(STDERR, "Usage: php clear_patients.php <tenant-uuid> [--dry-run]\n");
    exit(1);
}
$dryRun = in_array('--dry-run', $argv, true);

$app = require '/home/rajani/Desktop/Projects/afyanova_v4/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

if (! preg_match('/^[0-9a-f-]{36}$/i', $tenant)) {
    fwrite(STDERR, "Invalid tenant UUID.\n");
    exit(1);
}
DB::unprepared("SET app.tenant_id = '".$tenant."'");

$params = ['--tenant' => $tenant];
if ($dryRun) {
    $params['--dry-run'] = true;
} else {
    $params['--force'] = true;
}

$exit = Artisan::call('patient:clear', $params);
echo Artisan::output();
exit($exit);
