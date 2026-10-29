<?php

declare(strict_types=1);

namespace App\Domains\Identity\Contracts;

/**
 * Approved permission set for the Cashier staff role. Mirrors the demo
 * seeder composition exactly (till work + patient read + billing-clearance
 * queue set). Pricing/catalogue keys are never bolted on here — that is
 * the Billing Administrator role (separation of duties).
 */
final class CashierRoleDefinition
{
    public const KEY = 'cashier';

    public const PERMISSIONS = [
        'billing.access',
        'billing.accounts.manage',
        'billing.invoices.manage',
        'billing.payments.manage',
        'billing.cash_sessions.manage',
        'patient.patients.read',
        'queue.access',
        'queue.ticket.view',
        'queue.ticket.call',
        'queue.ticket.service',
    ];
}
