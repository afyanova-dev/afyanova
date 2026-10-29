<?php

declare(strict_types=1);

use App\Domains\Laboratory\Application\Command\CreateLabOrder;
use App\Domains\Laboratory\Application\Query\GetLabOrder;
use App\Domains\Laboratory\Application\Query\GetLabPanels;
use App\Domains\Laboratory\Domain\Exception\LabOrderHasNoItems;
use App\Domains\Laboratory\Domain\Exception\LabPanelAlreadyExists;
use App\Domains\Laboratory\Domain\Exception\LabPanelNotOrderable;
use App\Domains\Laboratory\Domain\Model\LabOrderItem;
use App\Models\Tenant;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('laboratory:define-panel creates a panel with member tests resolved from codes, audited', function () {
    $tenant = Tenant::factory()->create();
    $t = (string) $tenant->id;
    seedLabTestDefinition($t, ['code' => 'UREA', 'name' => 'Urea']);
    seedLabTestDefinition($t, ['code' => 'CREAT', 'name' => 'Creatinine']);

    $exit = Artisan::call('laboratory:define-panel', [
        'action' => 'define', '--tenant' => $t, '--code' => 'RFT', '--name' => 'Renal Function Panel',
        '--tests' => 'UREA, CREAT',
    ]);
    expect($exit)->toBe(0);

    $panel = Tenancy::run($t, fn () => DB::table('lab_panels')->where('code', 'RFT')->first());
    expect($panel)->not->toBeNull()->and((int) $panel->version)->toBe(1);

    $members = Tenancy::run($t, fn () => DB::table('lab_panel_members')->where('panel_id', $panel->id)->count());
    expect($members)->toBe(2);

    expect(Tenancy::run($t, fn () => DB::table('shared_audit_log')->where('action', 'laboratory.panel_defined')->where('target_id', $panel->id)->exists()))->toBeTrue();
});

test('define-panel rejects a duplicate active code', function () {
    $tenant = Tenant::factory()->create();
    $t = (string) $tenant->id;
    seedLabTestDefinition($t, ['code' => 'GLU']);
    Artisan::call('laboratory:define-panel', ['action' => 'define', '--tenant' => $t, '--code' => 'MP', '--name' => 'Metabolic', '--tests' => 'GLU']);

    expect(fn () => Artisan::call('laboratory:define-panel', ['action' => 'define', '--tenant' => $t, '--code' => 'MP', '--name' => 'Metabolic 2', '--tests' => 'GLU']))
        ->toThrow(LabPanelAlreadyExists::class);
});

test('CreateLabOrder expands a panel into one order item per member test, tagged with the panel', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;

    $urea = seedLabTestDefinition($t, ['code' => 'UREA', 'name' => 'Urea']);
    $creat = seedLabTestDefinition($t, ['code' => 'CREAT', 'name' => 'Creatinine']);
    $panelId = seedLabPanel($t, 'RFT', [(string) $urea->id, (string) $creat->id]);

    ['encounterId' => $enc, 'patientId' => $patientId] = seedActiveLabEncounter($t, $f, (string) $user->id);
    $orderId = (string) Str::uuid7();
    TenantContextScope::run($t, $f, fn () => app(CommandBus::class)->dispatch(new CreateLabOrder(
        orderId: $orderId, actorId: (string) $user->id, patientId: $patientId, encounterId: $enc,
        testDefinitionIds: [], panelIds: [$panelId],
    )));

    $items = TenantContextScope::run($t, $f, fn () => LabOrderItem::query()->where('order_id', $orderId)->get());
    expect($items)->toHaveCount(2)
        ->and($items->pluck('test_code')->sort()->values()->all())->toBe(['CREAT', 'UREA'])
        ->and($items->every(fn ($i) => (string) $i->ordered_via_panel_id === $panelId))->toBeTrue();

    $summary = TenantContextScope::run($t, $f, fn () => app(QueryBus::class)->dispatch(new GetLabOrder(orderId: $orderId)));
    expect($summary->items[0]->orderedViaPanelId)->toBe($panelId);
});

test('an explicitly ordered test that is also in a panel produces one item, untagged', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;

    $urea = seedLabTestDefinition($t, ['code' => 'UREA']);
    $creat = seedLabTestDefinition($t, ['code' => 'CREAT']);
    $panelId = seedLabPanel($t, 'RFT', [(string) $urea->id, (string) $creat->id]);

    ['encounterId' => $enc, 'patientId' => $patientId] = seedActiveLabEncounter($t, $f, (string) $user->id);
    $orderId = (string) Str::uuid7();
    TenantContextScope::run($t, $f, fn () => app(CommandBus::class)->dispatch(new CreateLabOrder(
        orderId: $orderId, actorId: (string) $user->id, patientId: $patientId, encounterId: $enc,
        testDefinitionIds: [(string) $urea->id], panelIds: [$panelId],
    )));

    $items = TenantContextScope::run($t, $f, fn () => LabOrderItem::query()->where('order_id', $orderId)->get());
    expect($items)->toHaveCount(2);
    $ureaItem = $items->firstWhere('test_code', 'UREA');
    expect($ureaItem->ordered_via_panel_id)->toBeNull();
});

test('CreateLabOrder rejects an inactive panel and an empty test+panel list', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;

    $urea = seedLabTestDefinition($t, ['code' => 'UREA']);
    $panelId = seedLabPanel($t, 'RFT', [(string) $urea->id], active: false);
    ['encounterId' => $enc, 'patientId' => $patientId] = seedActiveLabEncounter($t, $f, (string) $user->id);

    expect(fn () => new CreateLabOrder(
        orderId: (string) Str::uuid7(), actorId: (string) $user->id, patientId: $patientId, encounterId: $enc,
        testDefinitionIds: [], panelIds: [],
    ))->toThrow(LabOrderHasNoItems::class);

    expect(fn () => TenantContextScope::run($t, $f, fn () => app(CommandBus::class)->dispatch(new CreateLabOrder(
        orderId: (string) Str::uuid7(), actorId: (string) $user->id, patientId: $patientId, encounterId: $enc,
        testDefinitionIds: [], panelIds: [$panelId],
    ))))->toThrow(LabPanelNotOrderable::class);
});

test('GetLabPanels lists the tenant panel catalog with member codes', function () {
    ['tenant' => $tenant, 'facility' => $facility] = laboratoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $urea = seedLabTestDefinition($t, ['code' => 'UREA', 'name' => 'Urea']);
    seedLabPanel($t, 'RFT', [(string) $urea->id]);

    $panels = TenantContextScope::run($t, $f, fn () => app(QueryBus::class)->dispatch(new GetLabPanels(active: true)));
    expect($panels)->toHaveCount(1)
        ->and($panels[0]->code)->toBe('RFT')
        ->and($panels[0]->members[0]['testCode'])->toBe('UREA');
});
