<?php

declare(strict_types=1);

namespace Tests\Feature\Bus;

use App\Models\Tenant;
use App\Models\User;
use App\Shared\Bus\AuthorizesAgainst;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 | IC §5.1 / §12 — the bus loads the target aggregate through its repository and
 | calls {Aggregate}Policy::{ability}($actor, $aggregate, $command).
 */

uses(RefreshDatabase::class);

final class Widget extends AggregateRoot
{
    protected $table = 'test_widgets';

    protected $fillable = ['id', 'tenant_id', 'owner_id', 'name'];
}

final class WidgetRepository extends EloquentRepository
{
    protected function model(): string
    {
        return Widget::class;
    }
}

final class WidgetPolicy
{
    public function rename(User $actor, Widget $widget): bool
    {
        return (string) $widget->owner_id === (string) $actor->getKey();
    }
}

final readonly class RenameWidget implements AuthorizesAgainst
{
    public function __construct(public string $widgetId, public string $name) {}

    public function policyAbility(): string
    {
        return 'rename';
    }

    public function subjectId(): ?string
    {
        return $this->widgetId;
    }
}

final class RenameWidgetHandler
{
    public function __construct(private readonly WidgetRepository $widgets) {}

    public function __invoke(RenameWidget $command): void
    {
        $widget = $this->widgets->getById($command->widgetId);
        $widget->name = $command->name;
        $this->widgets->save($widget);
    }
}

beforeEach(function () {
    CommandBus::flush();
    Schema::create('test_widgets', function (Blueprint $t) {
        $t->uuid('id')->primary();
        $t->uuid('tenant_id');
        $t->uuid('owner_id');
        $t->string('name');
        $t->unsignedBigInteger('version')->default(0);
        $t->timestampsTz();
    });
    CommandBus::map([RenameWidget::class => RenameWidgetHandler::class]);
    Gate::policy(Widget::class, WidgetPolicy::class);
});

function makeWidget(string $tenantId, string $ownerId): string
{
    return TenantContextScope::run($tenantId, null, function () use ($tenantId, $ownerId): string {
        $id = (string) Str::uuid7();
        DB::table('test_widgets')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'owner_id' => $ownerId, 'name' => 'before',
        ]);

        return $id;
    });
}

it('loads the aggregate and allows the owner to act on it', function () {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->forTenant($tenant->id)->create();
    $this->actingAs($owner);
    $widgetId = makeWidget($tenant->id, $owner->id);

    TenantContextScope::run($tenant->id, null, fn () => app(CommandBus::class)->dispatch(new RenameWidget($widgetId, 'after')));

    expect(TenantContextScope::run($tenant->id, null, fn () => DB::table('test_widgets')->find($widgetId)->name))
        ->toBe('after');
});

it('denies a non-owner — the policy sees the loaded aggregate (§5.1)', function () {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->forTenant($tenant->id)->create();
    $stranger = User::factory()->forTenant($tenant->id)->create();
    $this->actingAs($stranger);
    $widgetId = makeWidget($tenant->id, $owner->id);

    TenantContextScope::run($tenant->id, null, fn () => app(CommandBus::class)->dispatch(new RenameWidget($widgetId, 'after')));
})->throws(AuthorizationException::class);

it('throws NotFound when the target aggregate does not exist', function () {
    $tenant = Tenant::factory()->create();
    $this->actingAs(User::factory()->forTenant($tenant->id)->create());

    TenantContextScope::run($tenant->id, null, fn () => app(CommandBus::class)->dispatch(
        new RenameWidget((string) Str::uuid7(), 'after')
    ));
})->throws(NotFound::class);

it('does NOT silently bypass — an AuthorizesAgainst command with no principal fails loudly', function () {
    $tenant = Tenant::factory()->create();
    $widgetId = makeWidget($tenant->id, (string) Str::uuid7());

    // no actingAs() — simulates a mis-wired job/scheduler dispatching an
    // AuthorizesAgainst command. It must NOT reach the handler; it must throw.
    auth()->guard()->logout();

    expect(fn () => TenantContextScope::run($tenant->id, null, fn () => app(CommandBus::class)->dispatch(new RenameWidget($widgetId, 'after'))))
        ->toThrow(AuthorizationException::class);

    // the handler never ran
    expect(TenantContextScope::run($tenant->id, null, fn () => DB::table('test_widgets')->find($widgetId)->name))->toBe('before');
});
