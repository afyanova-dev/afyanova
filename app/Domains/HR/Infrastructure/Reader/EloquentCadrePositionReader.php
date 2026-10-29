<?php

declare(strict_types=1);

namespace App\Domains\HR\Infrastructure\Reader;

use App\Domains\HR\Contracts\Dto\CadreSummary;
use App\Domains\HR\Contracts\Dto\PositionSummary;
use App\Domains\HR\Domain\CadrePositionReader;
use App\Domains\HR\Domain\Exception\CadreNotFoundException;
use App\Domains\HR\Domain\Exception\PositionNotFoundException;
use App\Domains\HR\Domain\Model\Cadre;
use App\Domains\HR\Domain\Model\Position;

/**
 * Eloquent implementation of CadrePositionReader (25-hr-and-workforce-domain.md §4).
 */
final class EloquentCadrePositionReader implements CadrePositionReader
{
    public function cadreSummary(string $cadreId): CadreSummary
    {
        /** @var Cadre|null $cadre */
        $cadre = Cadre::query()->whereKey($cadreId)->first();

        if ($cadre === null) {
            throw CadreNotFoundException::forId($cadreId);
        }

        return $this->toCadreSummary($cadre);
    }

    public function allCadres(?bool $activeOnly = true): array
    {
        $query = Cadre::query();

        if ($activeOnly === true) {
            $query->where('is_active', true);
        }

        return array_values($query->orderBy('name')->get()->map(fn (Cadre $c) => $this->toCadreSummary($c))->all());
    }

    public function positionSummary(string $positionId): PositionSummary
    {
        /** @var Position|null $position */
        $position = Position::query()->with('cadre')->whereKey($positionId)->first();

        if ($position === null) {
            throw PositionNotFoundException::forId($positionId);
        }

        return $this->toPositionSummary($position);
    }

    public function allPositions(?string $cadreId = null, ?bool $activeOnly = true): array
    {
        $query = Position::query()->with('cadre');

        if ($cadreId !== null && $cadreId !== '') {
            $query->where('cadre_id', $cadreId);
        }

        if ($activeOnly === true) {
            $query->where('is_active', true);
        }

        return array_values($query->orderBy('title')->get()->map(fn (Position $p) => $this->toPositionSummary($p))->all());
    }

    private function toCadreSummary(Cadre $c): CadreSummary
    {
        return new CadreSummary(
            cadreId: $c->id,
            tenantId: $c->tenant_id,
            code: $c->code,
            name: $c->name,
            category: $c->category->value,
            description: $c->description,
            isActive: $c->is_active,
            version: $c->version,
        );
    }

    private function toPositionSummary(Position $p): PositionSummary
    {
        return new PositionSummary(
            positionId: $p->id,
            tenantId: $p->tenant_id,
            code: $p->code,
            title: $p->title,
            cadreId: $p->cadre_id,
            cadreName: $p->cadre?->name,
            cadreCategory: $p->cadre?->category->value,
            description: $p->description,
            isActive: $p->is_active,
            version: $p->version,
        );
    }
}
