<?php

declare(strict_types=1);

use App\Shared\ReadModel\ReadModelRegistry;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../../../tests/Support/reporting.php';

it('satisfies ADR-093 with zero cross-prefix foreign keys in reporting tables', function (): void {
    /** @var list<object{table_name: string, constraint_name: string, foreign_table_name: string}> $fks */
    $fks = DB::select("
        SELECT
            tc.table_name,
            tc.constraint_name,
            ccu.table_name AS foreign_table_name
        FROM
            information_schema.table_constraints AS tc
            JOIN information_schema.key_column_usage AS kcu
              ON tc.constraint_name = kcu.constraint_name
              AND tc.table_schema = kcu.table_schema
            JOIN information_schema.constraint_column_usage AS ccu
              ON ccu.constraint_name = tc.constraint_name
              AND ccu.table_schema = tc.table_schema
        WHERE tc.constraint_type = 'FOREIGN KEY'
          AND tc.table_schema = 'public'
          AND tc.table_name LIKE 'rpt_%'
    ");

    expect($fks)->toBeArray();

    foreach ($fks as $fk) {
        expect($fk->foreign_table_name)->toStartWith('rpt_');
    }
});

it('declares all rpt_* tables in ReadModelRegistry (§6.4, ADR-094)', function (): void {
    $tables = DB::table('information_schema.tables')
        ->where('table_schema', 'public')
        ->where('table_name', 'like', 'rpt\_%')
        ->pluck('table_name')
        ->all();

    $declared = ReadModelRegistry::tables();

    foreach ($tables as $t) {
        expect($declared)->toContain($t);
    }
});
