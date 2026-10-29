<?php

declare(strict_types=1);

namespace App\Domains\HR\Infrastructure\Repository;

use App\Domains\HR\Domain\Exception\PositionNotFoundException;
use App\Domains\HR\Domain\Model\Position;
use App\Domains\HR\Domain\PositionRepository;
use App\Shared\Domain\EloquentRepository;

/**
 * Eloquent implementation of PositionRepository (25-hr-and-workforce-domain.md §4; ADR-206).
 */
final class EloquentPositionRepository extends EloquentRepository implements PositionRepository
{
    protected function model(): string
    {
        return Position::class;
    }

    public function getById(string $id): Position
    {
        return Position::query()->whereKey($id)->first()
            ?? throw PositionNotFoundException::forId($id);
    }

    public function getByIdForUpdate(string $id): Position
    {
        return Position::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw PositionNotFoundException::forId($id);
    }

    public function getByCode(string $code): Position
    {
        return Position::query()->where('code', strtoupper(trim($code)))->first()
            ?? throw PositionNotFoundException::forCode($code);
    }

    public function existsById(string $id): bool
    {
        return Position::query()->whereKey($id)->exists();
    }

    public function existsByCode(string $code): bool
    {
        return Position::query()->where('code', strtoupper(trim($code)))->exists();
    }
}
