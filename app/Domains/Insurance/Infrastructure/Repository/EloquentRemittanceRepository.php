<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Infrastructure\Repository;

use App\Domains\Insurance\Domain\Exception\RemittanceNotFound;
use App\Domains\Insurance\Domain\Model\Remittance;
use App\Domains\Insurance\Domain\Model\RemittanceLine;
use App\Domains\Insurance\Domain\RemittanceRepository;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Domain\EloquentRepository;

/**
 * Eloquent implementation of {@see RemittanceRepository} (IC §9; ADR-205).
 */
final class EloquentRemittanceRepository extends EloquentRepository implements RemittanceRepository
{
    protected function model(): string
    {
        return Remittance::class;
    }

    public function getById(string $id): Remittance
    {
        return Remittance::query()->with('lines')->whereKey($id)->first()
            ?? throw RemittanceNotFound::id($id);
    }

    public function getByIdForUpdate(string $id): Remittance
    {
        return Remittance::query()->with('lines')->whereKey($id)->lockForUpdate()->first()
            ?? throw RemittanceNotFound::id($id);
    }

    public function getByRemittanceNumber(string $remittanceNumber): ?Remittance
    {
        return Remittance::query()->with('lines')->where('remittance_number', $remittanceNumber)->first();
    }

    public function save(AggregateRoot $aggregate): void
    {
        parent::save($aggregate);

        if ($aggregate instanceof Remittance && $aggregate->relationLoaded('lines')) {
            /** @var RemittanceLine $line */
            foreach ($aggregate->lines as $line) {
                $line->save();
            }
        }
    }
}
