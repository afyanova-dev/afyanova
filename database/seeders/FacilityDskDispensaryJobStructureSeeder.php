<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\HR\Domain\Model\Cadre;
use App\Domains\HR\Domain\Model\Position;
use App\Domains\Organization\Domain\Model\Facility;
use App\Models\Tenant;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Seeds a starter workforce structure for a private dispensary.
 *
 * IMPORTANT:
 * - Codes are internal AfyaNova identifiers, not official government codes.
 * - Categories are internal AfyaNova classifications, not official government
 *   cadre categories.
 * - This practical private-dispensary template is not an official staffing norm.
 * - Optional services do not imply that the facility must provide them or employ
 *   someone in every seeded position.
 * - Cadres and positions are tenant-wide master records.
 *
 * Run explicitly:
 * php artisan db:seed --class=FacilityDskDispensaryJobStructureSeeder
 *
 * If using the fully-qualified class name, quote it so the shell does not
 * strip the backslashes:
 * php artisan db:seed --class='Database\Seeders\FacilityDskDispensaryJobStructureSeeder'
 */
final class FacilityDskDispensaryJobStructureSeeder extends Seeder
{
    private const RETIRED_CADRE_CODES = [
        'CLINICAL_OFFICER',
        'CLINICAL_ASSISTANT',
        'NURSE',
        'PHARMACEUTICAL_ASSISTANT',
        'LABORATORY_ASSISTANT',
        'LABORATORY_TECHNOLOGIST',
        'MEDICAL_ATTENDANT',
        'DATA_CLERK',
        'CLEANER',
        'SECURITY_GUARD',
    ];

    private const RETIRED_POSITION_CODES = [
        'CO_IN_CHARGE',
        'CO_OUTPATIENT',
        'CA_OUTPATIENT',
        'NURSE_OUTPATIENT',
        'NURSE_RCH',
        'LABORATORY_TECHNOLOGIST',
        'DATA_CLERK',
    ];

    private const FACILITY_NAME = 'DSK Dispensary';

    private const FACILITY_CODE = 'DSK_DISP';

    /**
     * Internal AfyaNova categories:
     * CLINICAL, NURSING, PHARMACY, LABORATORY, ALLIED, ADMIN, SUPPORT.
     *
     * @var list<array{
     *     code: string,
     *     name: string,
     *     category: string,
     *     description: string
     * }>
     */
    private const CADRES = [
        [
            'code' => 'CLINICAL_SERVICES',
            'name' => 'Clinical Services',
            'category' => 'CLINICAL',
            'description' => 'Clinical professionals providing assessment, diagnosis and treatment.',
        ],
        [
            'code' => 'NURSING_SERVICES',
            'name' => 'Nursing Services',
            'category' => 'NURSING',
            'description' => 'Nursing professionals providing nursing and applicable maternal and child health care.',
        ],
        [
            'code' => 'PHARMACEUTICAL_SERVICES',
            'name' => 'Pharmaceutical Services',
            'category' => 'PHARMACY',
            'description' => 'Professionals handling medicines and pharmaceutical services within their authorised scope.',
        ],
        [
            'code' => 'LABORATORY_SERVICES',
            'name' => 'Laboratory Services',
            'category' => 'LABORATORY',
            'description' => 'Laboratory professionals providing diagnostic services where offered by the dispensary.',
        ],
        [
            'code' => 'HEALTH_RECORDS_ADMIN',
            'name' => 'Health Records and Administration',
            'category' => 'ADMIN',
            'description' => 'Front-desk, health records, data and administrative staff.',
        ],
        [
            'code' => 'FACILITY_SUPPORT',
            'name' => 'Facility Support Services',
            'category' => 'SUPPORT',
            'description' => 'Patient assistance and facility support services.',
        ],
    ];

    /**
     * Positions represent jobs or posts within broader professional cadres.
     * Service areas such as outpatient and RCH are not positions; in-charge is
     * a responsibility, not a separate profession.
     *
     * Optional positions are included as master records; their presence does
     * not mean the facility must employ someone in every position.
     *
     * @var list<array{
     *     code: string,
     *     title: string,
     *     cadre: string,
     *     description: string
     * }>
     */
    private const POSITIONS = [
        [
            'code' => 'CLINICAL_OFFICER',
            'title' => 'Clinical Officer',
            'cadre' => 'CLINICAL_SERVICES',
            'description' => 'Provides clinical assessment, diagnosis and treatment within the authorised scope of practice.',
        ],
        [
            'code' => 'NURSE',
            'title' => 'Nurse',
            'cadre' => 'NURSING_SERVICES',
            'description' => 'Provides nursing care within the authorised scope of practice; service area may vary.',
        ],
        [
            'code' => 'PHARMACEUTICAL_ASSISTANT',
            'title' => 'Pharmaceutical Assistant',
            'cadre' => 'PHARMACEUTICAL_SERVICES',
            'description' => 'Provides authorised pharmaceutical and medicine-dispensing services where offered.',
        ],
        [
            'code' => 'LABORATORY_ASSISTANT',
            'title' => 'Laboratory Assistant',
            'cadre' => 'LABORATORY_SERVICES',
            'description' => 'Performs authorised laboratory services where the dispensary offers them.',
        ],
        [
            'code' => 'MEDICAL_ATTENDANT',
            'title' => 'Medical Attendant',
            'cadre' => 'FACILITY_SUPPORT',
            'description' => 'Provides patient assistance and delegated support duties.',
        ],
        [
            'code' => 'HEALTH_RECORDS_ASSISTANT',
            'title' => 'Health Records Assistant / Data Clerk',
            'cadre' => 'HEALTH_RECORDS_ADMIN',
            'description' => 'Supports patient records, routine health data entry and reporting.',
        ],
        [
            'code' => 'RECEPTION_ADMIN',
            'title' => 'Receptionist / Administrator',
            'cadre' => 'HEALTH_RECORDS_ADMIN',
            'description' => 'Supports reception, scheduling and routine administrative work.',
        ],
        [
            'code' => 'CLEANER',
            'title' => 'Cleaner',
            'cadre' => 'FACILITY_SUPPORT',
            'description' => 'Maintains facility cleanliness and hygiene.',
        ],
        [
            'code' => 'SECURITY_GUARD',
            'title' => 'Security Guard',
            'cadre' => 'FACILITY_SUPPORT',
            'description' => 'Maintains facility security and access control.',
        ],
    ];

    public function run(): void
    {
        $matchingTenants = [];

        foreach (Tenant::query()->get() as $tenant) {
            $hasTargetFacility = TenantContextScope::run(
                (string) $tenant->id,
                null,
                fn (): bool => Facility::query()
                    ->where('name', self::FACILITY_NAME)
                    ->where('code', self::FACILITY_CODE)
                    ->where('facility_type', 'DISPENSARY')
                    ->exists(),
            );

            if ($hasTargetFacility) {
                $matchingTenants[] = $tenant;
            }
        }

        if (count($matchingTenants) !== 1) {
            throw new RuntimeException(sprintf(
                'Expected exactly one tenant owning the DSK dispensary facility (%s / %s); found %d. No job structure was seeded.',
                self::FACILITY_NAME,
                self::FACILITY_CODE,
                count($matchingTenants),
            ));
        }

        /** @var Tenant $tenant */
        $tenant = $matchingTenants[0];

        TenantContextScope::run(
            (string) $tenant->id,
            null,
            fn () => $this->seedJobStructure((string) $tenant->id),
        );
    }

    private function seedJobStructure(string $tenantId): void
    {
        $cadresByCode = [];

        foreach (self::CADRES as $attributes) {
            $cadre = Cadre::query()->updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'code' => $attributes['code'],
                ],
                [
                    'name' => $attributes['name'],
                    'category' => $attributes['category'],
                    'description' => $attributes['description'],
                    'is_active' => true,
                ],
            );

            $cadresByCode[$attributes['code']] = $cadre;
        }

        foreach (self::POSITIONS as $attributes) {
            $cadre = $cadresByCode[$attributes['cadre']];

            Position::query()->updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'code' => $attributes['code'],
                ],
                [
                    'title' => $attributes['title'],
                    'cadre_id' => $cadre->getKey(),
                    'description' => $attributes['description'],
                    'is_active' => true,
                ],
            );
        }

        Position::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('code', self::RETIRED_POSITION_CODES)
            ->whereNotIn('code', array_column(self::POSITIONS, 'code'))
            ->where('is_active', true)
            ->update([
                'is_active' => false,
                'version' => DB::raw('version + 1'),
                'updated_at' => now(),
            ]);

        Cadre::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('code', self::RETIRED_CADRE_CODES)
            ->whereDoesntHave('positions', static fn ($query) => $query->where('is_active', true))
            ->where('is_active', true)
            ->update([
                'is_active' => false,
                'version' => DB::raw('version + 1'),
                'updated_at' => now(),
            ]);
    }
}
