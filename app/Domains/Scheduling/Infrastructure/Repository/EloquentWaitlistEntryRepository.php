<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Infrastructure\Repository;

use App\Domains\Scheduling\Domain\Exception\WaitlistEntryNotFoundException;
use App\Domains\Scheduling\Domain\Model\WaitlistEntry;
use App\Domains\Scheduling\Domain\ValueObject\WaitlistStatus;
use App\Domains\Scheduling\Domain\WaitlistEntryRepository;
use App\Shared\Domain\EloquentRepository;
use Carbon\CarbonImmutable;

final class EloquentWaitlistEntryRepository extends EloquentRepository implements WaitlistEntryRepository
{
    protected function model(): string
    {
        return WaitlistEntry::class;
    }

    public function getById(string $id): WaitlistEntry
    {
        return WaitlistEntry::query()->whereKey($id)->first()
            ?? throw WaitlistEntryNotFoundException::forId($id);
    }

    public function getByIdForUpdate(string $id): WaitlistEntry
    {
        return WaitlistEntry::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw WaitlistEntryNotFoundException::forId($id);
    }

    public function existsByNumber(string $waitlistNumber): bool
    {
        return WaitlistEntry::query()->where('waitlist_number', $waitlistNumber)->exists();
    }

    /**
     * @return list<WaitlistEntry>
     */
    public function getLapsedOffers(CarbonImmutable $asOf): array
    {
        /** @var list<WaitlistEntry> */
        return WaitlistEntry::query()
            ->where('status', WaitlistStatus::OFFERED->value)
            ->where('offered_until', '<=', $asOf)
            ->get()
            ->all();
    }
}
