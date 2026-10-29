<?php

declare(strict_types=1);

use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../../../tests/Support/hr.php';

it('returns field validation errors for missing required staff details', function (): void {
    $fixture = hrFixture();

    $this->actingAs($fixture['user'])
        ->from(route('hr.staff.create'))
        ->post(route('hr.staff.store'), [
            'first_name' => 'Amina',
            'middle_name' => '',
            'last_name' => 'Mrema',
            'date_of_birth' => '',
            'gender' => 'FEMALE',
            'department_id' => '',
            'unit_id' => '',
            'effective_from' => '',
            'position_id' => '',
            'contract_type' => '',
            'employment_start_date' => '',
            'fte' => '1.00',
        ])
        ->assertRedirect(route('hr.staff.create'))
        ->assertSessionHasErrors(['date_of_birth', 'effective_from']);

    expect(TenantContextScope::run(
        (string) $fixture['tenant']->id,
        (string) $fixture['facility']->id,
        fn (): int => DB::table('hr_staff')->count(),
    ))->toBe(0);
});

it('attaches employment validation errors to the missing field', function (): void {
    $fixture = hrFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $cadreId = defineCadreHelper($tenantId);
    $positionId = definePositionHelper($tenantId, $cadreId);

    $this->actingAs($fixture['user'])
        ->from(route('hr.staff.create'))
        ->post(route('hr.staff.store'), [
            'first_name' => 'Amina',
            'middle_name' => '',
            'last_name' => 'Mrema',
            'date_of_birth' => '1990-04-12',
            'gender' => 'FEMALE',
            'department_id' => '',
            'unit_id' => '',
            'effective_from' => now()->toDateString(),
            'position_id' => $positionId,
            'contract_type' => '',
            'employment_start_date' => '',
            'fte' => '1.00',
        ])
        ->assertRedirect(route('hr.staff.create'))
        ->assertSessionHasErrors(['contract_type']);

    expect(TenantContextScope::run(
        $tenantId,
        $facilityId,
        fn (): int => DB::table('hr_staff')->count(),
    ))->toBe(0);
});
