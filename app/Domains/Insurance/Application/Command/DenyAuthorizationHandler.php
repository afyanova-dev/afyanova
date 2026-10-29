<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Domains\Insurance\Domain\AuthorizationRepository;
use Carbon\CarbonImmutable;

/**
 * Orchestrates `DenyAuthorization` (ADR-202 D-B). `AuthorizationRepository` is
 * the FIRST repository param so the bus resolves `AuthorizationPolicy`
 * (per-subject `approve`).
 */
final class DenyAuthorizationHandler
{
    public function __construct(private readonly AuthorizationRepository $authorizations) {}

    public function __invoke(DenyAuthorization $command): void
    {
        $authorization = $this->authorizations->getByIdForUpdate($command->authorizationId);

        $authorization->deny(
            reasonCode: $command->reasonCode,
            note: $command->note,
            byUserId: $command->actorId,
            at: CarbonImmutable::now(),
        );

        $this->authorizations->save($authorization);
    }
}
