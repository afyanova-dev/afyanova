<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TanzaniaDiagnosisCatalogSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $dataFile = database_path('data/tanzania_diagnoses.php');
        if (! file_exists($dataFile)) {
            throw new \RuntimeException("Diagnosis catalogue data file not found: {$dataFile}");
        }

        /** @var list<array{code: string, name: string, category: string, aliases?: string}> $catalog */
        $catalog = require $dataFile;
        $now = now();

        DB::transaction(function () use ($catalog, $now): void {
            $codes = [];
            foreach ($catalog as $entry) {
                $codes[] = strtoupper(trim((string) $entry['code']));
            }

            $existing = DB::table('clin_diagnosis_catalog')
                ->where('code_system', 'ICD-10')
                ->whereIn('code', array_values(array_unique($codes)))
                ->get(['id', 'code'])
                ->keyBy('code');

            foreach ($catalog as $entry) {
                $code = strtoupper(trim((string) $entry['code']));
                $payload = [
                    'name' => trim((string) $entry['name']),
                    'aliases' => isset($entry['aliases']) ? trim((string) $entry['aliases']) : null,
                    'category' => strtoupper(trim((string) $entry['category'])),
                    'updated_at' => $now,
                ];

                if (isset($existing[$code])) {
                    DB::table('clin_diagnosis_catalog')->updateOrInsert(
                        ['code_system' => 'ICD-10', 'code' => $code],
                        $payload,
                    );
                } else {
                    DB::table('clin_diagnosis_catalog')->updateOrInsert(
                        ['code_system' => 'ICD-10', 'code' => $code],
                        [
                            ...$payload,
                            'id' => (string) Str::uuid(),
                            'is_favourite' => false,
                            'active' => true,
                            'created_at' => $now,
                        ],
                    );
                    $existing->put($code, (object) ['code' => $code]);
                }
            }
        });
    }
}
