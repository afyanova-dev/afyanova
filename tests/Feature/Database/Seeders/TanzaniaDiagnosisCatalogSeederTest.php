<?php

declare(strict_types=1);

namespace Tests\Feature\Database\Seeders;

use Database\Seeders\TanzaniaDiagnosisCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('the Tanzania diagnosis catalogue is idempotent and preserves management flags', function (): void {
    $catalog = require database_path('data/tanzania_diagnoses.php');

    $this->seed(TanzaniaDiagnosisCatalogSeeder::class);
    $this->seed(TanzaniaDiagnosisCatalogSeeder::class);

    $seededCodes = array_values(array_unique(array_map(
        fn (array $entry): string => strtoupper(trim($entry['code'])),
        $catalog,
    )));

    $first = DB::table('clin_diagnosis_catalog')->where('code', $seededCodes[0])->first();

    DB::table('clin_diagnosis_catalog')->where('id', $first->id)->update([
        'active' => false,
        'is_favourite' => true,
    ]);

    $this->seed(TanzaniaDiagnosisCatalogSeeder::class);

    expect(DB::table('clin_diagnosis_catalog')->count())->toBe(count($seededCodes));
    expect(DB::table('clin_diagnosis_catalog')
        ->where('code_system', 'ICD-10')
        ->distinct()
        ->count('code'))->toBe(count($seededCodes));

    $preserved = DB::table('clin_diagnosis_catalog')->where('id', $first->id)->first();
    expect((bool) $preserved->active)->toBeFalse();
    expect((bool) $preserved->is_favourite)->toBeTrue();
});
