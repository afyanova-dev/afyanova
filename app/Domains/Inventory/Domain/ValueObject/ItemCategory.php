<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain\ValueObject;

use InvalidArgumentException;

/**
 * The optional item-category set for an Item Master entry
 * (18-inventory-and-warehousing-domain.md §179; ADR-170 D-C — "§179 suggested,
 * not enforced"). Set-validated on the write path (the `BillingServiceCategory`
 * precedent).
 *
 * INTERIM: these are NEMLIT-shaped pharmacological / supply groups so values
 * entered now stay broadly compatible with the full NEMLIT group list that
 * Inv.0 will curate (design + rationale: `inventory-item-master-spec.md` §4/§7).
 * This is NOT the final taxonomy.
 */
final class ItemCategory
{
    public const ALL = [
        // Medicines — NEMLIT-shaped pharmacological groups (interim).
        'ANAESTHETIC',
        'ANALGESIC',
        'ANTIALLERGIC',
        'ANTIBIOTIC',
        'ANTICONVULSANT',
        'ANTIDIABETIC',
        'ANTIFUNGAL',
        'ANTIMALARIAL',
        'ANTIPARASITIC',
        'ANTIRETROVIRAL',
        'ANTITUBERCULOSIS',
        'ANTIVIRAL',
        'CARDIOVASCULAR',
        'DERMATOLOGICAL',
        'GASTROINTESTINAL',
        'HAEMATOLOGICAL',
        'HORMONAL',
        'IMMUNOLOGICAL',
        'MUSCULOSKELETAL',
        'NEUROLOGICAL',
        'OPHTHALMIC',
        'PSYCHOTHERAPEUTIC',
        'RESPIRATORY',
        'VACCINE',
        'VITAMIN_MINERAL',
        'FLUID_ELECTROLYTE',

        // Non-medicine.
        'CONSUMABLE',
        'DRESSING',
        'DIAGNOSTIC_REAGENT',
        'EQUIPMENT',
        'DEVICE',
        'OTHER',
    ];

    public static function assert(string $value): string
    {
        $value = strtoupper(trim($value));

        if (! in_array($value, self::ALL, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid inventory item category [%s]. Permitted: %s.',
                $value,
                implode(', ', self::ALL),
            ));
        }

        return $value;
    }
}
