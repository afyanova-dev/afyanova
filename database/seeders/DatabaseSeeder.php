<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Application seed entry point.
 *
 * Scope is encoded in each seeder name:
 * - Global*: tenant-less shared reference data.
 * - Tenant*: data owned by one tenant.
 * - Facility*: data targeted at one named facility.
 *
 * Facility seeders are invoked explicitly and are not called from this entry point.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            GlobalReferenceDataSeeder::class,
            TenantTanzaniaMedicineCatalogSeeder::class,
        ]);
    }
}
