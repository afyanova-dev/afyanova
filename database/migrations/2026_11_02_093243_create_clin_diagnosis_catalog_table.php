<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('clin_diagnosis_catalog', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code')->nullable();
            $table->string('code_system')->default('ICD-10');
            $table->string('name');
            $table->text('aliases')->nullable();
            $table->string('category')->index();
            $table->boolean('is_favourite')->default(false)->index();
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
            $table->unique(['code_system', 'code']);
            $table->index(['active', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clin_diagnosis_catalog');
    }
};
