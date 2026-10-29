<?php

declare(strict_types=1);

namespace App\Domains\Identity\Contracts;

/**
 * Approved permission set for the Billing Administrator staff role.
 * Mirrors the demo seeder composition exactly (price list + failed-charge
 * worklist). Till work is the Cashier role; user admin is Facility Admin.
 */
final class BillingAdminRoleDefinition
{
    public const KEY = 'billing_admin';

    public const PERMISSIONS = [
        'billing.access',
        'billing.catalog.manage',
        'billing.charges.manage',
        'patient.patients.read',
    ];
}
