<?php

declare(strict_types=1);

namespace Tests\Unit\Bus;

use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\TenantContext;
use InvalidArgumentException;
use RuntimeException;

beforeEach(function () {
    QueryBus::flush();
    app(TenantContext::class)->establish('01a05948-0000-7000-8000-000000000001', null);
    $this->bus = new QueryBus(app());
});

it('dispatches a query to a registered handler when tenant context is set', function () {
    $query = new class {};

    $handlerClass = get_class(new class
    {
        public function __invoke(object $q): string
        {
            return 'result';
        }
    });

    QueryBus::map([$query::class => $handlerClass]);

    expect($this->bus->dispatch($query))->toBe('result');
});

it('throws InvalidArgumentException for an unregistered query', function () {
    $this->bus->dispatch(new class {});
})->throws(InvalidArgumentException::class);

it('refuses to dispatch without an established tenant context (IC §6.1)', function () {
    $bus = new QueryBus(app());
    app()->forgetInstance(TenantContext::class);

    $query = new class {};
    QueryBus::map([$query::class => get_class(new class
    {
        public function __invoke(object $q): null
        {
            return null;
        }
    })]);

    $bus->dispatch($query);
})->throws(RuntimeException::class);
