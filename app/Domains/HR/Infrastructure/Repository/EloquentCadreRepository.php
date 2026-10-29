<?php

declare(strict_types=1);

namespace App\Domains\HR\Infrastructure\Repository;

use App\Domains\HR\Domain\CadreRepository;
use App\Domains\HR\Domain\Exception\CadreNotFoundException;
use App\Domains\HR\Domain\Model\Cadre;
use App\Shared\Domain\EloquentRepository;

/**
 * Eloquent implementation of CadreRepository (25-hr-and-workforce-domain.md §4; ADR-206).
 */
final class EloquentCadreRepository extends EloquentRepository implements CadreRepository
{
    protected function model(): string
    {
        return Cadre::class;
    }

    public function getById(string $id): Cadre
    {
        return Cadre::query()->whereKey($id)->first()
            ?? throw CadreNotFoundException::forId($id);
    }

    public function getByIdForUpdate(string $id): Cadre
    {
        return Cadre::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw CadreNotFoundException::forId($id);
    }

    public function getByCode(string $code): Cadre
    {
        return Cadre::query()->where('code', strtoupper(trim($code)))->first()
            ?? throw CadreNotFoundException::forCode($code);
    }

    public function existsById(string $id): bool
    {
        return Cadre::query()->whereKey($id)->exists();
    }

    public function existsByCode(string $code): bool
    {
        return Cadre::query()->where('code', strtoupper(trim($code)))->exists();
    }
}
