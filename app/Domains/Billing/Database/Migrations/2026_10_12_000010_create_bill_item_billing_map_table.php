<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 10 (increment Bill.3a) — the `inv_items.id → billing_service` map
 * (20-billing-and-payments-domain.md §237; ADR-189 D-A).
 *
 * How a `pharmacy.MedicationDispensed` event's `itemId` (an `inv_items.id`)
 * resolves to a billable service. A billing admin authors it — nothing
 * auto-creates a mapping. `inv_item_id` is a plain uuid, NOT validated against
 * Inventory (ZERO cross-domain; a bad id simply never matches an event).
 * Mutable — `MapItemToBillingService` supersedes the prior active row.
 * One active mapping per item (many items → one service is allowed; one item →
 * many services is deferred, §237).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_item_billing_map', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            $table->uuid('inv_item_id');                        // an inv_items.id — plain uuid, NO FK, NOT validated
            $table->uuid('billing_service_id');                 // an existing bill_billing_services row (handler-validated)
            $table->boolean('active')->default(true);

            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'billing_service_id']);
        });

        // ADR-189 D-A — one active mapping per item.
        DB::statement(
            'create unique index bill_item_billing_map_one_active_per_item
                 on bill_item_billing_map (tenant_id, inv_item_id)
              where active'
        );

        Rls::enable('bill_item_billing_map');
    }

    public function down(): void
    {
        Rls::disable('bill_item_billing_map');
        Schema::dropIfExists('bill_item_billing_map');
    }
};
