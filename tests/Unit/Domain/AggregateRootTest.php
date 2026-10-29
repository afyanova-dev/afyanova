<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Shared\Domain\AggregateRoot;

class TestAggregate extends AggregateRoot
{
    public function doSomething(): void
    {
        $this->recordThat(new class {});
    }
}

it('starts with version 0', function () {
    $aggregate = new TestAggregate;

    expect($aggregate->version)->toBe(0);
});

it('records events and releases them, emptying the internal array', function () {
    $aggregate = new TestAggregate;
    $aggregate->doSomething();

    $events = $aggregate->releaseEvents();

    expect($events)->toHaveCount(1)
        ->and($aggregate->releaseEvents())->toHaveCount(0);
});
