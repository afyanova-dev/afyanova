<?php

declare(strict_types=1);

namespace App\Domains\Patient\Console;

use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ClearPatientsCommand extends Command
{
    protected $signature = 'patient:clear
        {--tenant= : Tenant UUID to clear}
        {--facility= : Exact facility name to resolve the tenant to clear}
        {--patient=* : One or more patient UUIDs to clear}
        {--all : Clear all patients in the database}
        {--dry-run : Show affected rows without deleting anything}
        {--force : Confirm the destructive delete operation}';

    protected $description = 'Delete patient identities and patient-linked records from the database.';

    public function handle(): int
    {
        $tenantId = $this->option('tenant');
        $facilityName = $this->option('facility');

        if (! empty($tenantId) && ! empty($facilityName)) {
            $this->error('Use either --tenant or --facility, not both.');

            return self::FAILURE;
        }

        if (! empty($facilityName)) {
            $matches = [];
            $tenantIds = DB::table('shared_tenants')->pluck('id');

            foreach ($tenantIds as $id) {
                $candidateTenantId = (string) $id;
                $facilities = TenantContextScope::run(
                    $candidateTenantId,
                    null,
                    fn (): array => DB::table('org_facilities')
                        ->where('name', (string) $facilityName)
                        ->pluck('id')
                        ->all(),
                );

                if ($facilities !== []) {
                    $matches[] = $candidateTenantId;
                }
            }

            if ($matches === []) {
                $this->error(sprintf('No facility found with the exact name "%s".', $facilityName));

                return self::FAILURE;
            }
            if (count($matches) > 1) {
                $this->error(sprintf('More than one tenant has a facility named "%s"; use --tenant with a UUID.', $facilityName));

                return self::FAILURE;
            }

            $tenantId = $matches[0];
        }

        if (! empty($tenantId)) {
            return TenantContextScope::run(
                (string) $tenantId,
                null,
                fn (): int => $this->clearPatients((string) $tenantId),
            );
        }

        return $this->clearPatients(null);
    }

    private function clearPatients(?string $tenantId): int
    {
        $patientIds = $this->option('patient');
        $all = (bool) $this->option('all');

        if (! $all && $tenantId === null && empty($patientIds)) {
            $this->error('Provide --tenant, --facility, --patient, or --all. Use --dry-run first to inspect what would be deleted.');

            return self::FAILURE;
        }

        $query = DB::table('pat_patients');

        if ($all) {
            $query->whereNotNull('id');
        }

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        if (! empty($patientIds)) {
            $query->whereIn('id', array_map('strval', $patientIds));
        }

        $patients = $query->pluck('id');

        if ($patients->isEmpty()) {
            $this->info('No matching patients were found.');

            return self::SUCCESS;
        }

        $patientList = $patients->values()->all();
        $summary = $this->summarize($patientList);

        if ($this->option('dry-run')) {
            $this->info(sprintf('Found %d patient record(s).', count($patientList)));
            if ($summary === []) {
                $this->info('No patient-linked data found for the selected patient(s).');

                return self::SUCCESS;
            }

            $this->table(['Table', 'Rows'], $summary);
            $this->warn('Dry run only. Re-run with --force to delete these records permanently.');

            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            $this->warn('This is destructive. Re-run with --force to confirm and execute the deletion.');

            return self::FAILURE;
        }

        $removedTotal = 0;
        foreach ($this->tablesToClean() as $table => $columns) {
            $deleted = 0;

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }
                $deleted += DB::table($table)
                    ->whereIn($column, $patientList)
                    ->delete();
            }

            if ($deleted > 0) {
                $removedTotal += $deleted;
            }
        }

        $deletedPatients = DB::table('pat_patients')->whereIn('id', $patientList)->delete();

        $this->info(sprintf('Deleted %d patient record(s) and %d related row(s).', $deletedPatients, $removedTotal));

        return self::SUCCESS;
    }

    /**
     * Return all patient-linked tables and the columns that reference patient ids.
     *
     * This intentionally covers the patient aggregate tables plus the most common cross-domain tables.
     */
    private function tablesToClean(): array
    {
        $tables = [
            'pat_patient_registrations' => ['patient_id'],
            'pat_patient_identifiers' => ['patient_id'],
            'pat_patient_relationships' => ['patient_id', 'related_patient_id'],
            'pat_patient_contacts' => ['patient_id'],
            'pat_patient_addresses' => ['patient_id'],
            'pat_patient_identity_history' => ['patient_id'],
            'enc_encounters' => ['patient_id'],
            'clin_notes' => ['patient_id'],
            'clin_note_amendments' => ['patient_id'],
            'clin_vital_sign_observations' => ['patient_id'],
            'clin_diagnoses' => ['patient_id'],
            'clin_problems' => ['patient_id'],
            'clin_problem_history' => ['patient_id'],
            'clin_allergies' => ['patient_id'],
            'clin_allergy_history' => ['patient_id'],
            'clin_care_team_memberships' => ['patient_id'],
            'clin_plans' => ['patient_id'],
            'clin_plan_history' => ['patient_id'],
            'clin_prescribing_decisions' => ['patient_id'],
            'clin_triage_assessments' => ['patient_id'],
            'clin_procedures' => ['patient_id'],
            'clin_procedure_amendments' => ['patient_id'],
            'lab_orders' => ['patient_id'],
            'lab_results' => ['patient_id'],
            'lab_specimens' => ['patient_id'],
            'rad_orders' => ['patient_id'],
            'rad_reports' => ['patient_id'],
            'pharmacy_prescriptions' => ['patient_id'],
            'pharmacy_dispenses' => ['patient_id'],
            'inv_prescriptions' => ['patient_id'],
            'bill_financial_accounts' => ['patient_id'],
            'bill_billable_events' => ['patient_id'],
            'bill_charges' => ['patient_id'],
            'bill_invoices' => ['patient_id'],
            'bill_payments' => ['patient_id'],
            'bill_receipts' => ['patient_id'],
            'bill_write_offs' => ['patient_id'],
            'bill_adjustments' => ['patient_id'],
            'ins_member_coverages' => ['patient_id'],
            'ins_authorizations' => ['patient_id'],
            'ins_claims' => ['patient_id'],
            'care_referrals' => ['patient_id'],
            'care_episodes' => ['patient_id'],
            'care_episode_encounters' => ['patient_id'],
            'care_followups' => ['patient_id'],
            'bw_bed_occupancies' => ['patient_id'],
            'bw_bed_reservations' => ['patient_id'],
            'qms_queue_entries' => ['patient_id'],
            'qms_patient_movements' => ['patient_id'],
        ];

        foreach ($this->discoverPatientIdTables() as $table => $column) {
            $tables[$table] ??= [$column];
        }

        return $tables;
    }

    /**
     * Discover any extra tables that have a patient_id column so this command remains useful as the schema grows.
     */
    private function discoverPatientIdTables(): array
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $rows = DB::select(
                "SELECT table_name, column_name
                 FROM information_schema.columns
                 WHERE table_schema = current_schema()
                   AND column_name = 'patient_id'
                   AND table_name NOT LIKE 'migrations'",
            );
        } else {
            $database = config('database.connections.'.config('database.default').'.database');

            if ($database === null || $database === '') {
                return [];
            }

            $rows = DB::select(
                "SELECT table_name, column_name
                 FROM information_schema.columns
                 WHERE table_schema = ?
                   AND column_name = 'patient_id'
                   AND table_name NOT LIKE 'migrations'",
                [$database],
            );
        }

        $tables = [];
        foreach ($rows as $row) {
            $tables[$row->table_name] = $row->column_name;
        }

        return $tables;
    }

    /**
     * For dry-run output, show the number of rows that would be removed per table.
     */
    private function summarize(array $patientList): array
    {
        $summary = [];

        foreach ($this->tablesToClean() as $table => $columns) {
            $count = 0;

            foreach ($columns as $column) {
                if (Schema::hasColumn($table, $column)) {
                    $count += DB::table($table)->whereIn($column, $patientList)->count();
                }
            }

            if ($count > 0) {
                $summary[] = [$table, $count];
            }
        }

        return $summary;
    }
}
