<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['inv_item_levels', 'inv_goods_receipt_lines'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->unsignedInteger('version')->default(1);
            });
        }
    }

    public function down(): void
    {
        foreach (['inv_goods_receipt_lines', 'inv_item_levels'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn('version');
            });
        }
    }
};
