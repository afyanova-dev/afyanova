<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const BUILTIN_ROLE_KEYS = [
        'facility_admin',
        'receptionist',
        'cashier',
        'billing_admin',
        'pharmacist',
        'doctor',
        'nurse',
        'tenant_admin',
    ];

    public function up(): void
    {
        Schema::table('idn_roles', function (Blueprint $table): void {
            $table->boolean('is_protected')->default(false);
        });

        DB::table('idn_roles')
            ->whereIn('key', self::BUILTIN_ROLE_KEYS)
            ->update(['is_protected' => true]);
    }

    public function down(): void
    {
        Schema::table('idn_roles', function (Blueprint $table): void {
            $table->dropColumn('is_protected');
        });
    }
};
