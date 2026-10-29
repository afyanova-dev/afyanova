<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Infrastructure\Repository;

use App\Domains\Insurance\Domain\AuthorizationRepository;
use App\Domains\Insurance\Domain\Exception\AuthorizationNotFound;
use App\Domains\Insurance\Domain\Model\Authorization;
use App\Shared\Domain\EloquentRepository;

/**
 * Eloquent implementation of {@see AuthorizationRepository} (IC §9; ADR-202 D-B).
 */
final class EloquentAuthorizationRepository extends EloquentRepository implements AuthorizationRepository
{
    protected function model(): string
    {
        return Authorization::class;
    }

    public function getById(string $id): Authorization
    {
        return Authorization::query()->whereKey($id)->first()
            ?? throw AuthorizationNotFound::id($id);
    }

    public function getByIdForUpdate(string $id): Authorization
    {
        return Authorization::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw AuthorizationNotFound::id($id);
    }
}
