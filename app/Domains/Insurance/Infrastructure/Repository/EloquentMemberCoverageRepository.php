<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Infrastructure\Repository;

use App\Domains\Insurance\Domain\MemberCoverageRepository;
use App\Domains\Insurance\Domain\Model\MemberCoverage;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of {@see MemberCoverageRepository} (IC §9; ADR-199 D-A).
 */
final class EloquentMemberCoverageRepository extends EloquentRepository implements MemberCoverageRepository
{
    protected function model(): string
    {
        return MemberCoverage::class;
    }

    public function getById(string $id): MemberCoverage
    {
        return MemberCoverage::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(MemberCoverage::class, $id);
    }

    public function getByIdForUpdate(string $id): MemberCoverage
    {
        return MemberCoverage::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(MemberCoverage::class, $id);
    }

    public function exists(string $id): bool
    {
        return MemberCoverage::query()->whereKey($id)->exists();
    }
}
