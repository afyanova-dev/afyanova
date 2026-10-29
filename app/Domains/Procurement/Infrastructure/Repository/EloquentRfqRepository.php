<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Infrastructure\Repository;

use App\Domains\Procurement\Domain\Model\Rfq;
use App\Domains\Procurement\Domain\RfqRepository;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

final class EloquentRfqRepository extends EloquentRepository implements RfqRepository
{
    protected function model(): string
    {
        return Rfq::class;
    }

    public function getById(string $id): Rfq
    {
        return Rfq::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(Rfq::class, $id);
    }

    public function getByIdForUpdate(string $id): Rfq
    {
        return Rfq::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(Rfq::class, $id);
    }
}
