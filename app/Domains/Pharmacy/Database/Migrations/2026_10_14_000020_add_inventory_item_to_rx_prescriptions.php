<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rx_prescriptions', function (Blueprint $table): void {
            $table->uuid('inventory_item_id')->nullable()->after('medication_code');
            $table->index(['tenant_id', 'inventory_item_id']);
        });
    }

    public function down(): void
    {
        Schema::table('rx_prescriptions', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'inventory_item_id']);
            $table->dropColumn('inventory_item_id');
        });
    }
};
