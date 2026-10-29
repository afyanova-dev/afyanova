<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Rls::allowPlatformAuditRead('shared_audit_log');
    }

    public function down(): void
    {
        Rls::restoreTenantOnlyAuditRead('shared_audit_log');
    }
};
