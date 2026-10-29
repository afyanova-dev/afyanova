<?php

declare(strict_types=1);

namespace App\Domains\Identity\Contracts;

final class PharmacistRoleDefinition
{
    public const KEY = 'pharmacist';

    public const PERMISSIONS = [
        'pharmacy.access',
        'pharmacy.prescriptions.accept',
        'pharmacy.orders.manage',
        'pharmacy.dispensing.record',
        'pharmacy.dispensing.reverse',
        'pharmacy.pickup.record',
        'inventory.access',
        // ADR-184 HD-5 — DispenseHandler nests InventoryApi::reserveStock +
        // recordConsumption as the same actor, so the pharmacist must hold
        // both keys or every dispense fails on the nested authorization.
        'inventory.reservations.manage',
        'inventory.consumption.record',
        // Phase 8 — the pharmacy counter receives stock from the main store and
        // sends it back, so the pharmacist moves it.
        'inventory.transfers.create',
        // Phase 8 — the pharmacy counter is counted by the pharmacist.
        'inventory.counts.manage',
        // Phase 8 — the pharmacist raises expiry removals and count
        // corrections, and can approve the facility admin's (never their own).
        'inventory.adjustments.create',
        'inventory.adjustments.approve',
        'patient.patients.read',
        'patient.patients.create_pharmacy_only',
        // Shape B — the pharmacy also runs out of stock and may raise a
        // department purchase requisition (read + create only; approval stays a
        // different actor, §24).
        'procurement.access',
        'procurement.requisitions.create',
    ];
}
