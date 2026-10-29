<?php

declare(strict_types=1);

namespace Tests\Unit\Bus;

use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContext;
use InvalidArgumentException;
use RuntimeException;

/*
 | Pure guard checks (no DB). The full dispatch path — Tenancy::run, the
 | middleware pipeline, audit + idempotency — is covered by
 | tests/Feature/Bus/CommandBusIntegrationTest.
 */

beforeEach(function () {
    CommandBus::flush();
    $this->bus = new CommandBus(app());
});

it('throws for an unregistered command', function () {
    $this->bus->dispatch(new class {});
})->throws(InvalidArgumentException::class);

it('refuses to dispatch without an established tenant context (IC §5, §11.1)', function () {
    app()->forgetInstance(TenantContext::class);

    $command = new class {};
    CommandBus::map([$command::class => get_class(new class
    {
        public function __invoke(object $c): void {}
    })]);

    $this->bus->dispatch($command);
})->throws(RuntimeException::class);

it('exposes the handler registered for a command', function () {
    $handler = get_class(new class
    {
        public function __invoke(object $c): void {}
    });
    CommandBus::map(['App\\Fake\\DoThing' => $handler]);

    expect(CommandBus::handlerFor('App\\Fake\\DoThing'))->toBe($handler)
        ->and(CommandBus::handlerFor('App\\Fake\\Unknown'))->toBeNull();
});
