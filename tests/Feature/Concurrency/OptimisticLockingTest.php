<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency;

use App\Shared\Concurrency\ConcurrencyConflict;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 | PHASE 4 gate — Optimistic Locking (blueprint.md)
 */

uses(RefreshDatabase::class);

final class DummyAggregate extends AggregateRoot
{
    protected $table = 'dummy_aggregates';

    protected $fillable = ['id', 'tenant_id', 'name'];
}

beforeEach(function () {
    Schema::create('dummy_aggregates', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('tenant_id');
        $table->string('name');
        $table->integer('version')->default(0);
        $table->timestamps();
    });
});

test('aggregate version bumps on save', function () {
    $tenantId = (string) Str::uuid7();

    TenantContextScope::run($tenantId, null, function () use ($tenantId) {
        $aggregate = new DummyAggregate([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'name' => 'First Name',
        ]);

        $aggregate->save();
        expect($aggregate->version)->toBe(0);

        // Update 0 -> 1
        $aggregate->name = 'Second Name';
        $aggregate->save();

        expect($aggregate->version)->toBe(1);
        $this->assertDatabaseHas('dummy_aggregates', [
            'id' => $aggregate->id,
            'version' => 1,
        ]);

        // Update 1 -> 2
        $aggregate->name = 'Third Name';
        $aggregate->save();

        expect($aggregate->version)->toBe(2);
        $this->assertDatabaseHas('dummy_aggregates', [
            'id' => $aggregate->id,
            'version' => 2,
        ]);
    });
});

test('a versioned save touches ONLY its own row (regression: performUpdate must scope by id)', function () {
    $tenantId = (string) Str::uuid7();

    TenantContextScope::run($tenantId, null, function () use ($tenantId) {
        $a = new DummyAggregate(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'name' => 'A']);
        $b = new DummyAggregate(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'name' => 'B']);
        $a->save();
        $b->save();

        // both start at version 0
        expect($a->version)->toBe(0)->and($b->version)->toBe(0);

        // modify + save A only
        $a->name = 'A-modified';
        $a->save();

        $aFresh = DummyAggregate::find($a->id);
        $bFresh = DummyAggregate::find($b->id);

        expect($aFresh->name)->toBe('A-modified')
            ->and($aFresh->version)->toBe(1)
            // B must be completely untouched — the old bug bumped it because the
            // UPDATE was `WHERE version = 0` with no id predicate.
            ->and($bFresh->name)->toBe('B')
            ->and($bFresh->version)->toBe(0);
    });
});

test('concurrent modification throws ConcurrencyConflict', function () {
    $tenantId = (string) Str::uuid7();
    $id = (string) Str::uuid7();

    TenantContextScope::run($tenantId, null, function () use ($tenantId, $id) {
        // Create initial version
        $aggregate = new DummyAggregate([
            'id' => $id,
            'tenant_id' => $tenantId,
            'name' => 'Initial',
        ]);
        $aggregate->save();

        // Instance A loads version 0
        $instanceA = DummyAggregate::find($id);

        // Instance B loads version 0
        $instanceB = DummyAggregate::find($id);

        // Instance A saves successfully -> version 1
        $instanceA->name = 'A Update';
        $instanceA->save();
        expect($instanceA->version)->toBe(1);

        // Instance B tries to save -> throws ConcurrencyConflict
        $instanceB->name = 'B Update';
        $instanceB->save();
    });
})->throws(ConcurrencyConflict::class);

test('ConcurrencyConflict has correct error code and status', function () {
    $conflict = ConcurrencyConflict::on(DummyAggregate::class, '1234');

    expect($conflict->errorCode())->toBe('conflict')
        ->and($conflict->httpStatus())->toBe(409);
});
