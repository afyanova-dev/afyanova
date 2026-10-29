<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Attention;

use App\Domains\Identity\Domain\Model\StaffIdentity;
use App\Domains\Scheduling\Application\Command\BookAppointment;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

function attentionFixture(array $permissions = ['patient.patients.read']): array
{
    return queueingFixture($permissions);
}

// Slice pages unbuilt: disable missing-page enforcement so response shapes
// stay testable until the .tsx lands (then delete this block).
beforeEach(function (): void {
    config([
        'inertia.pages.ensure_pages_exist' => false,
        'inertia.testing.ensure_pages_exist' => false,
    ]);
});

test('the attention board requires the patient read permission', function (): void {
    $fixture = attentionFixture(['queue.access']);

    $this->actingAs($fixture['user'])
        ->get(route('attention.index'))
        ->assertForbidden();
});

test('the attention board renders the four lists', function (): void {
    $fixture = attentionFixture();

    $this->actingAs($fixture['user'])
        ->get(route('attention.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Attention/Index')
            ->where('scope', 'all')
            ->has('unqueued')
            ->has('longWaits')
            ->has('missed')
            ->has('referrals')
            ->where('mineUnlinked', false));
});

test('guests are redirected to login from the attention board', function (): void {
    $this->get(route('attention.index'))->assertRedirect(route('login'));
});

test('mine scope only shows the clinician’s own missed appointments', function (): void {
    $fixture = attentionFixture(['patient.patients.read', 'sch.access', 'sch.appointment.book', 'sch.resource.manage', 'sch.appointment_type.manage', 'hr.access', 'hr.person.manage', 'hr.staff.read', 'hr.staff.manage']);
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;

    TenantContextScope::run($tenantId, null, function () use ($tenantId, $fixture): void {
        StaffIdentity::query()->updateOrCreate(
            ['user_id' => $fixture['user']->id],
            ['tenant_id' => $tenantId, 'is_staff' => true, 'staff_number' => 'EMP-900'],
        );
    });

    // Provider resources must reference real HR staff: create staff first,
    // link the user to it, then register the resource against the staff id.
    $personId = createPersonHelper($tenantId, 'Mine', null, 'Doctor');
    $staffId = createStaffHelper($tenantId, $personId, facilityId: $facilityId);
    TenantContextScope::run($tenantId, null, function () use ($fixture, $staffId): void {
        StaffIdentity::query()->where('user_id', $fixture['user']->id)->update(['staff_ref' => $staffId]);
    });

    $mineResource = registerSchedulableResourceHelper($tenantId, $facilityId, $staffId, 'Dr. Mine');
    $otherStaffId = createStaffHelper($tenantId, createPersonHelper($tenantId, 'Other', null, 'Doctor'), facilityId: $facilityId);
    $otherResource = registerSchedulableResourceHelper($tenantId, $facilityId, $otherStaffId, 'Dr. Other');
    $typeId = defineAppointmentTypeHelper($tenantId, 'GEN', 'General', 30, facilityId: $facilityId);

    // Book directly so the id is known for the no-show flip.
    $mineId = (string) Str::uuid7();
    $otherId = (string) Str::uuid7();
    TenantContextScope::run($tenantId, $facilityId, function () use ($facilityId, $mineResource, $otherResource, $typeId, $mineId, $otherId): void {
        foreach ([[$mineResource, $mineId], [$otherResource, $otherId]] as [$resourceId, $id]) {
            app(CommandBus::class)->dispatch(new BookAppointment(
                patientId: (string) Str::uuid7(),
                resourceId: $resourceId,
                appointmentTypeId: $typeId,
                scheduledStart: CarbonImmutable::now()->subHours(3)->toIso8601String(),
                scheduledEnd: CarbonImmutable::now()->subHours(2)->toIso8601String(),
                facilityId: $facilityId,
                appointmentId: $id,
            ));
            DB::table('sch_appointments')->where('id', $id)->update(['status' => 'NO_SHOW']);
        }
    });

    $this->actingAs($fixture['user'])
        ->get(route('attention.index', ['scope' => 'mine']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('scope', 'mine')
            ->has('missed', 1)
            ->where('missed.0.id', $mineId)
            ->where('mineUnlinked', false));
});
