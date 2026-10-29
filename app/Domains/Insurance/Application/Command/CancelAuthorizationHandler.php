<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Domains\Insurance\Domain\AuthorizationRepository;
use Carbon\CarbonImmutable;

/**
 * Orchestrates `CancelAuthorization` (ADR-202 D-B). `AuthorizationRepository`
 * is the FIRST repository param so the bus resolves `AuthorizationPolicy`
 * (per-subject `approve`).
 */
final class CancelAuthorizationHandler
{
    public function __construct(private readonly AuthorizationRepository $authorizations) {}

    public function __invoke(CancelAuthorization $command): void
    {
        $authorization = $this->authorizations->getByIdForUpdate($command->authorizationId);

        $authorization->cancel(
            reasonCode: $command->reasonCode,
            byUserId: $command->actorId,
            at: CarbonImmutable::now(),
        );

        $this->authorizations->save($authorization);
    }
}
