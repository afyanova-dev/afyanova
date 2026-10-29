<?php

declare(strict_types=1);

namespace App\Domains\Authentication\Contracts;

final class FacilityUserRoleCatalog
{
    public const ADMINISTRATOR = 'facility_admin';

    public const HR_MANAGER = 'facility_staff_manager';

    public const CLINICIAN = 'doctor';

    public const STAFF_ROLES = [
        'nurse' => 'Nurse',
        self::CLINICIAN => 'Clinician',
        'pharmacist' => 'Pharmacist',
        'laboratory_technician' => 'Laboratory Technician',
        'receptionist' => 'Receptionist',
        'cashier' => 'Cashier',
        'billing_admin' => 'Billing',
    ];

    public const REQUIRED_CAPABILITIES = [
        'pharmacist' => 'PHARMACY',
        'laboratory_technician' => 'LABORATORY',
    ];

    public static function label(string $roleKey): ?string
    {
        return match ($roleKey) {
            self::ADMINISTRATOR => 'Facility Admin',
            self::HR_MANAGER => 'HR / Staff Manager',
            default => self::STAFF_ROLES[$roleKey] ?? null,
        };
    }

    /**
     * @param  array<int, string>  $enabledCapabilities
     */
    public static function isAvailableAtFacility(
        string $roleKey,
        array $enabledCapabilities,
    ): bool {
        $requiredCapability = self::REQUIRED_CAPABILITIES[$roleKey] ?? null;

        return $requiredCapability === null || in_array($requiredCapability, $enabledCapabilities, true);
    }

    /**
     * @param  list<string>  $roleKeys
     * @return list<array{key: string, label: string, requiredCapability?: string}>
     */
    public static function options(array $roleKeys): array
    {
        return array_values(array_map(
            static fn (string $key): array => [
                'key' => $key,
                'label' => self::label($key) ?? $key,
                ...(isset(self::REQUIRED_CAPABILITIES[$key])
                    ? ['requiredCapability' => self::REQUIRED_CAPABILITIES[$key]]
                    : []),
            ],
            array_filter($roleKeys, static fn (string $key): bool => self::label($key) !== null),
        ));
    }
}
