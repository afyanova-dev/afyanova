<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Encounter\Presentation;

use App\Domains\Encounter\Application\Command\CreateEncounter;
use App\Domains\Encounter\Domain\Enum\EncounterType;
use App\Domains\Encounter\Domain\Model\Encounter;
use App\Domains\Identity\Contracts\FacilityAdminRoleDefinition;
use App\Domains\Identity\Contracts\ReceptionistRoleDefinition;
use App\Domains\Patient\Domain\Model\Patient;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
});

it('verifies admin persona retains administrative plus queue permissions and cannot access Reception', function (): void {
    $admin = User::where('email', 'admin@afyanova.local')->firstOrFail();
    $scope = app(ScopeChecker::class);

    TenantContextScope::run($admin->tenant_id, null, function () use ($admin, $scope): void {
        $selectable = $scope->selectableFacilities($admin);
        expect($selectable)->toHaveCount(2);

        $perms = $scope->effectivePermissions($admin, $selectable[0]);
        $expectedPermissions = FacilityAdminRoleDefinition::PERMISSIONS;
        sort($perms);
        sort($expectedPermissions);

        expect($perms)->toBe($expectedPermissions)
            ->and($perms)->toContain('identity.access')
            ->and($perms)->toContain('organization.access')
            ->and($perms)->toContain('queue.ticket.place')
            ->and($perms)->toContain('queue.definition.manage')
            ->and($perms)->not->toContain('encounter.encounters.read')
            ->and($perms)->not->toContain('sch.access')
            ->and($perms)->not->toContain('patient.patients.read');
    });

    // Attempting to access reception workspace as admin returns 403 Forbidden
    $response = $this->actingAs($admin)->get('/workspaces/reception');
    $response->assertStatus(403);
});

it('verifies reception persona has front-desk permissions and successfully accesses Reception workspace', function (): void {
    $receptionist = User::where('email', 'reception@afyanova.local')->firstOrFail();
    $scope = app(ScopeChecker::class);

    TenantContextScope::run($receptionist->tenant_id, null, function () use ($receptionist, $scope): void {
        $selectable = $scope->selectableFacilities($receptionist);
        expect($selectable)->toHaveCount(1); // Assigned only to Demo General Hospital

        $facilityId = $selectable[0];
        $perms = $scope->effectivePermissions($receptionist, $facilityId);
        $expectedPermissions = ReceptionistRoleDefinition::PERMISSIONS;
        sort($perms);
        sort($expectedPermissions);

        expect($perms)->toBe($expectedPermissions);
    });

    // Accessing reception workspace as receptionist returns 200 OK with Reception/Index
    $response = $this->actingAs($receptionist)->get('/workspaces/reception');
    $response->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Reception/Index')
        ->has('facilityId')
        ->has('date')
        ->has('encounters')
        ->has('appointments')
        ->has('metrics')
        ->has('referrals')
        ->has('rosterStatus')
        ->where('auth.user.name', 'Demo Receptionist')
        ->where('auth.can', fn ($can) => ($can['encounter.encounters.read'] ?? null) === true
            && ($can['sch.access'] ?? null) === true
            && ($can['patient.patients.read'] ?? null) === true
            && ($can['patient.patients.create'] ?? null) === true
            && empty($can['identity.users.manage'])
            && empty($can['clinical.access'])
        )
    );
});

it('verifies reception persona cannot access administrative or clinical operations outside its boundary', function (): void {
    $receptionist = User::where('email', 'reception@afyanova.local')->firstOrFail();
    $scope = app(ScopeChecker::class);

    TenantContextScope::run($receptionist->tenant_id, null, function () use ($receptionist, $scope): void {
        $selectable = $scope->selectableFacilities($receptionist);
        $facilityId = $selectable[0];
        $perms = $scope->effectivePermissions($receptionist, $facilityId);

        // Explicitly confirm zero privilege leakage
        expect($perms)->not->toContain('identity.users.manage')
            ->and($perms)->not->toContain('identity.roles.manage')
            ->and($perms)->not->toContain('organization.capabilities.manage')
            ->and($perms)->not->toContain('organization.structure.manage')
            ->and($perms)->not->toContain('clinical.access')
            ->and($perms)->not->toContain('clinical.notes.create')
            ->and($perms)->not->toContain('pharmacy.access')
            ->and($perms)->not->toContain('billing.payments.manage')
            ->and($perms)->not->toContain('queue.definition.manage');
    });
});

it('lands reception persona on the dashboard', function (): void {
    $receptionist = User::where('email', 'reception@afyanova.local')->firstOrFail();

    $this->actingAs($receptionist)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Index'));
});

it('labels reception weeks correctly across month and year boundaries', function (): void {
    $receptionist = User::where('email', 'reception@afyanova.local')->firstOrFail();

    // Same-month week (control): Sep 20 - 26, 2026.
    $this->actingAs($receptionist)->get('/workspaces/reception?date=2026-09-23')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Reception/Index')
            ->where('date', '2026-09-23')
            ->where('weekLabel', 'Week of September 20 - 26, 2026')
            ->has('days', 7)
            ->where('days.3.date', '2026-09-23')
            ->where('days.3.isSelected', true)
        );

    // Cross-month week: Sep 27 - Oct 3, 2026.
    $this->actingAs($receptionist)->get('/workspaces/reception?date=2026-09-28')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('date', '2026-09-28')
            ->where('weekLabel', 'Week of September 27 - October 3, 2026')
            ->where('days.0.date', '2026-09-27')
            ->where('days.6.date', '2026-10-03')
        );

    // Cross-year week: Dec 27, 2026 - Jan 2, 2027.
    $this->actingAs($receptionist)->get('/workspaces/reception?date=2026-12-30')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('date', '2026-12-30')
            ->where('weekLabel', 'Week of December 27, 2026 - January 2, 2027')
        );
});

it('falls back to today instead of crashing on malformed reception dates', function (): void {
    $receptionist = User::where('email', 'reception@afyanova.local')->firstOrFail();
    $today = CarbonImmutable::now((string) config('app.facility_timezone', 'Africa/Dar_es_Salaam'))->toDateString();

    $this->actingAs($receptionist)->get('/workspaces/reception?date=not-a-date')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('date', $today));

    $this->actingAs($receptionist)->get('/workspaces/reception?date=2026-13-45')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('date', $today));
});

it('carries unresolved visits onto today without changing historical or future boards', function (): void {
    $receptionist = User::where('email', 'reception@afyanova.local')->firstOrFail();
    $this->actingAs($receptionist);
    $tenantId = (string) $receptionist->tenant_id;
    $facilityId = TenantContextScope::run(
        $tenantId,
        null,
        fn () => app(ScopeChecker::class)->selectableFacilities($receptionist)[0]
    );
    $facilityTimeZone = (string) config('app.facility_timezone', 'Africa/Dar_es_Salaam');
    $today = CarbonImmutable::now($facilityTimeZone)->toDateString();
    $yesterday = CarbonImmutable::now($facilityTimeZone)->subDay()->toDateString();
    $tomorrow = CarbonImmutable::now($facilityTimeZone)->addDay()->toDateString();

    // ARRIVED encounter opened today.
    $todayEncId = TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $receptionist): string {
        $patientId = (string) Patient::factory()->forTenant($tenantId)->create([
            'given_name' => 'Leo',
            'family_name' => 'Leo',
            'display_name' => 'Leo Leo',
        ])->id;
        $encounterId = (string) Str::uuid7();
        app(CommandBus::class)->dispatch(new CreateEncounter(
            encounterId: $encounterId,
            patientId: $patientId,
            actorId: (string) $receptionist->id,
            encounterType: EncounterType::Outpatient,
            planned: false,
        ));

        return $encounterId;
    });

    // ARRIVED encounter opened yesterday, still open.
    $yesterdayEncId = TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $receptionist, $yesterday): string {
        $patientId = (string) Patient::factory()->forTenant($tenantId)->create([
            'given_name' => 'Zawadi',
            'family_name' => 'Zawadi',
            'display_name' => 'Zawadi Zawadi',
        ])->id;
        $encounterId = (string) Str::uuid7();
        app(CommandBus::class)->dispatch(new CreateEncounter(
            encounterId: $encounterId,
            patientId: $patientId,
            actorId: (string) $receptionist->id,
            encounterType: EncounterType::Outpatient,
            planned: false,
        ));
        Encounter::whereKey($encounterId)->update([
            'arrived_at' => $yesterday.' 09:00:00',
            'created_at' => $yesterday.' 09:00:00',
        ]);

        return $encounterId;
    });

    $contains = fn (string $encounterId): \Closure => static fn ($encs): bool => collect($encs)
        ->contains('encounterId', $encounterId);

    // Today's active board includes an unresolved prior-day visit so reception
    // can continue the workflow, while the daily metrics stay date-specific.
    $this->actingAs($receptionist)->get('/workspaces/reception')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('date', $today)
            ->where('encounters', $contains($todayEncId))
            ->where('encounters', $contains($yesterdayEncId))
            ->where('metrics.totalEncounters', 1)
        );

    // Historical boards remain date-specific; today's visit must not leak back.
    $this->actingAs($receptionist)->get("/workspaces/reception?date={$yesterday}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('date', $yesterday)
            ->where('encounters', $contains($yesterdayEncId))
            ->where('encounters', static fn ($encs): bool => ! collect($encs)->contains('encounterId', $todayEncId))
        );

    // Tomorrow: neither visit — future boards show appointments only.
    $this->actingAs($receptionist)->get("/workspaces/reception?date={$tomorrow}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('date', $tomorrow)
            ->where('encounters', static fn ($encs): bool => ! collect($encs)->contains('encounterId', $todayEncId))
            ->where('encounters', static fn ($encs): bool => ! collect($encs)->contains('encounterId', $yesterdayEncId))
        );
});
