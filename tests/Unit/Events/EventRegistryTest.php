<?php

declare(strict_types=1);

namespace Tests\Unit\Events;

use App\Shared\Events\EventRegistry;

beforeEach(function () {
    EventRegistry::flush();
});

it('registers and retrieves listeners', function () {
    $eventClass = 'App\Events\SomeEvent';
    $listenerClass = 'App\Listeners\SomeListener';

    EventRegistry::listen($eventClass, $listenerClass);

    expect(EventRegistry::listeners($eventClass))->toHaveCount(1)
        ->and(EventRegistry::listeners($eventClass)[0])->toBe($listenerClass);
});

it('registers multiple listeners for the same event class', function () {
    $eventClass = 'App\Events\SomeEvent';
    $listener1 = 'App\Listeners\Listener1';
    $listener2 = 'App\Listeners\Listener2';

    EventRegistry::listen($eventClass, $listener1);
    EventRegistry::listen($eventClass, $listener2);

    expect(EventRegistry::listeners($eventClass))->toHaveCount(2)
        ->and(EventRegistry::listeners($eventClass))->toContain($listener1, $listener2);
});

it('returns all registered listeners', function () {
    $event1 = 'Event1';
    $event2 = 'Event2';

    EventRegistry::listen($event1, 'Listener1');
    EventRegistry::listen($event2, 'Listener2');

    $all = EventRegistry::all();

    expect($all)->toHaveCount(2)
        ->and($all)->toHaveKey($event1)
        ->and($all)->toHaveKey($event2);
});

it('flushes all listeners', function () {
    EventRegistry::listen('Event1', 'Listener1');

    EventRegistry::flush();

    expect(EventRegistry::all())->toBeEmpty();
});
