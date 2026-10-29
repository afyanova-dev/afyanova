<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Domains\Insurance\Domain\AuthorizationRepository;
use App\Domains\Insurance\Domain\ValueObject\AuthorizationStatus;
use Carbon\CarbonImmutable;

/**
 * Orchestrates `ExpireAuthorization` (ADR-202 D-B).
 *
 * Defensive no-op unless the authorization is APPROVED / PARTIALLY_APPROVED
 * AND strictly past its `valid_to` date — a sweep may race a human action,
 * and a double sweep is safe.
 *
 * `AuthorizationRepository` is the first repository parameter → `AuthorizationPolicy`
 * would resolve, but `ExpireAuthorization` is not `AuthorizesAgainst`, so the
 * bus skips the check (system command).
 */
final class ExpireAuthorizationHandler
{
    public function __construct(
        private readonly AuthorizationRepository $authorizations,
    ) {}

    public function __invoke(ExpireAuthorization $command): void
    {
        $authorization = $this->authorizations->getByIdForUpdate($command->authorizationId);

        if (! in_array($authorization->status, AuthorizationStatus::EFFECTIVE, true)
            || $authorization->valid_to === null
            || $authorization->valid_to->toDateString() >= CarbonImmutable::now()->toDateString()) {
            return; // not a candidate — already resolved, or still within its validity term
        }

        $authorization->expire(CarbonImmutable::now());

        $this->authorizations->save($authorization);
    }
}
