<?php

declare(strict_types=1);

namespace Tests\Unit\Idempotency;

use App\Shared\Idempotency\ClaimResult;
use App\Shared\Idempotency\IdempotencyConflict;

it('creates an in-progress claim', function () {
    $result = ClaimResult::inProgress();

    expect($result->isInProgress())->toBeTrue()
        ->and($result->isCompleted())->toBeFalse()
        ->and($result->isClaimed())->toBeFalse();
});

it('creates a completed claim with a stored result', function () {
    $data = ['data' => 'success'];
    $result = ClaimResult::completed($data);

    expect($result->isCompleted())->toBeTrue()
        ->and($result->isInProgress())->toBeFalse()
        ->and($result->storedResult)->toBe($data);
});

it('has correct exception code and http status for IdempotencyConflict', function () {
    $exception = IdempotencyConflict::keyInProgress('DummyScope', 'key-123');

    expect($exception->errorCode())->toBe('in_progress')
        ->and($exception->httpStatus())->toBe(409);
});
