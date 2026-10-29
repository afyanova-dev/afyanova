<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clin_procedure_definitions', function (Blueprint $table): void {
            $table->string('protocol_reference')->nullable();
        });

        Schema::table('clin_procedures', function (Blueprint $table): void {
            $table->string('authorization_basis')->nullable();
            $table->string('authorization_protocol_reference')->nullable();
        });

        DB::table('clin_procedures')
            ->whereNotNull('authorized_at')
            ->update(['authorization_basis' => 'CLINICIAN']);
    }

    public function down(): void
    {
        Schema::table('clin_procedures', function (Blueprint $table): void {
            $table->dropColumn(['authorization_basis', 'authorization_protocol_reference']);
        });

        Schema::table('clin_procedure_definitions', function (Blueprint $table): void {
            $table->dropColumn('protocol_reference');
        });
    }
};
