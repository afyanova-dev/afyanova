<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PHASE 3 — organization-wide, tenant-less reference data
 * (docs/00-foundation/06-facility-levels-and-capabilities.md §6–§10, §70;
 * IC §23 — idempotent, keyed on stable `code`).
 */
class GlobalReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->upsert('org_facility_types', 'code', [
            ['code' => 'DISPENSARY', 'name' => 'Dispensary'],
            ['code' => 'HEALTH_CENTRE', 'name' => 'Health Centre'],
            ['code' => 'HOSPITAL', 'name' => 'Hospital'],
            ['code' => 'SPECIALIZED_HOSPITAL', 'name' => 'Specialized Hospital'],
            ['code' => 'CLINIC', 'name' => 'Clinic'],
            ['code' => 'DIAGNOSTIC_CENTRE', 'name' => 'Diagnostic Centre'],
            ['code' => 'MATERNITY_HOME', 'name' => 'Maternity Home'],
        ]);

        $this->upsert('org_care_levels', 'code', [
            ['code' => 'PRIMARY', 'name' => 'Community / Primary', 'rank' => 1],
            ['code' => 'HEALTH_CENTRE', 'name' => 'Health Centre', 'rank' => 2],
            ['code' => 'DISTRICT', 'name' => 'District', 'rank' => 3],
            ['code' => 'REGIONAL', 'name' => 'Regional', 'rank' => 4],
            ['code' => 'ZONAL', 'name' => 'Zonal / Consultant', 'rank' => 5],
            ['code' => 'NATIONAL', 'name' => 'National / Specialized', 'rank' => 6],
        ]);

        $this->upsert('org_ownership_types', 'code', [
            ['code' => 'PUBLIC', 'name' => 'Public'],
            ['code' => 'PRIVATE', 'name' => 'Private'],
            ['code' => 'FAITH_BASED', 'name' => 'Faith-based'],
            ['code' => 'NGO', 'name' => 'NGO'],
            ['code' => 'COMMUNITY', 'name' => 'Community'],
            ['code' => 'OTHER', 'name' => 'Other'],
        ]);

        // Registry — §10 capability list, §70 categories.
        $this->upsert('org_capabilities', 'code', [
            ['code' => 'OUTPATIENT_CARE', 'name' => 'Outpatient Care', 'category' => 'CARE', 'feature_key' => 'outpatient', 'required_domain' => 'Clinical'],
            ['code' => 'EMERGENCY_CARE', 'name' => 'Emergency Care', 'category' => 'CARE', 'feature_key' => 'emergency', 'required_domain' => 'Clinical'],
            ['code' => 'OBSERVATION', 'name' => 'Observation', 'category' => 'CARE', 'feature_key' => 'observation', 'required_domain' => 'Clinical'],
            ['code' => 'INPATIENT_CARE', 'name' => 'Inpatient Care', 'category' => 'CARE', 'feature_key' => 'inpatient', 'required_domain' => 'Bed & Ward Management'],
            ['code' => 'MATERNITY', 'name' => 'Maternity', 'category' => 'CARE', 'feature_key' => 'maternity', 'required_domain' => 'Clinical'],
            ['code' => 'LABORATORY', 'name' => 'Laboratory', 'category' => 'DIAGNOSTICS', 'feature_key' => 'laboratory', 'required_domain' => 'Laboratory'],
            ['code' => 'RADIOLOGY', 'name' => 'Radiology', 'category' => 'DIAGNOSTICS', 'feature_key' => 'radiology', 'required_domain' => 'Radiology'],
            ['code' => 'PHARMACY', 'name' => 'Pharmacy', 'category' => 'MEDICATION', 'feature_key' => 'pharmacy', 'required_domain' => 'Pharmacy'],
            ['code' => 'THEATRE', 'name' => 'Operating Theatre', 'category' => 'SURGICAL', 'feature_key' => 'theatre', 'required_domain' => 'Theatre'],
            ['code' => 'ANAESTHESIA', 'name' => 'Anaesthesia', 'category' => 'SURGICAL', 'feature_key' => 'anaesthesia', 'required_domain' => 'Theatre'],
            ['code' => 'RECOVERY', 'name' => 'Post-operative Recovery', 'category' => 'SURGICAL', 'feature_key' => 'recovery', 'required_domain' => 'Theatre'],
            ['code' => 'ICU', 'name' => 'Intensive Care Unit', 'category' => 'CRITICAL_CARE', 'feature_key' => 'icu', 'required_domain' => 'Bed & Ward Management'],
            ['code' => 'DENTAL', 'name' => 'Dental', 'category' => 'CARE', 'feature_key' => 'dental', 'required_domain' => 'Clinical'],
            ['code' => 'PHYSIOTHERAPY', 'name' => 'Physiotherapy', 'category' => 'CARE', 'feature_key' => 'physiotherapy', 'required_domain' => 'Clinical'],
            ['code' => 'DIALYSIS', 'name' => 'Dialysis', 'category' => 'CARE', 'feature_key' => 'dialysis', 'required_domain' => 'Clinical'],
            ['code' => 'MENTAL_HEALTH', 'name' => 'Mental Health', 'category' => 'CARE', 'feature_key' => 'mental_health', 'required_domain' => 'Clinical'],
            ['code' => 'MORTUARY', 'name' => 'Mortuary', 'category' => 'SUPPORT', 'feature_key' => 'mortuary', 'required_domain' => null],
            ['code' => 'AMBULANCE', 'name' => 'Ambulance', 'category' => 'SUPPORT', 'feature_key' => 'ambulance', 'required_domain' => null],
            ['code' => 'BLOOD_SERVICE', 'name' => 'Blood Service', 'category' => 'SUPPORT', 'feature_key' => 'blood_service', 'required_domain' => 'Laboratory'],
        ]);

        // Explicit dependencies (§16) — never "HOSPITAL → enable everything" (§17).
        $this->upsertDeps([
            'THEATRE' => ['ANAESTHESIA', 'RECOVERY'],
            'ICU' => ['INPATIENT_CARE'],
            'INPATIENT_CARE' => ['PHARMACY'],
            'MATERNITY' => ['INPATIENT_CARE'],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function upsert(string $table, string $key, array $rows): void
    {
        foreach ($rows as $row) {
            DB::table($table)->updateOrInsert(
                [$key => $row[$key]],
                [...$row, 'id' => (string) Str::uuid7(), 'updated_at' => now(), 'created_at' => now()],
            );
        }
    }

    /**
     * @param  array<string, list<string>>  $map
     */
    private function upsertDeps(array $map): void
    {
        foreach ($map as $code => $deps) {
            foreach ($deps as $dep) {
                DB::table('org_capability_dependencies')->updateOrInsert(
                    ['capability_code' => $code, 'depends_on_code' => $dep],
                    ['id' => (string) Str::uuid7(), 'updated_at' => now(), 'created_at' => now()],
                );
            }
        }
    }
}
