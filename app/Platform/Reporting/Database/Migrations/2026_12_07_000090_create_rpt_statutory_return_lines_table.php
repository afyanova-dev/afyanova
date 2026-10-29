<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rpt_statutory_return_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            $table->uuid('return_id')->index();
            $table->string('data_element_code', 64)->index();
            $table->string('category_option_combo', 64)->default('default');
            $table->decimal('value', 15, 4)->default(0);
            $table->text('comment')->nullable();

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'return_id', 'data_element_code']);
        });

        Rls::enable('rpt_statutory_return_lines');
    }

    public function down(): void
    {
        Rls::disable('rpt_statutory_return_lines');
        Schema::dropIfExists('rpt_statutory_return_lines');
    }
};
