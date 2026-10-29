<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Infrastructure\Reader;

use App\Domains\Laboratory\Contracts\Dto\LabPanelSummary;
use App\Domains\Laboratory\Domain\LabPanelReader;
use App\Domains\Laboratory\Domain\Model\LabPanel;
use App\Domains\Laboratory\Domain\Model\LabPanelMember;
use App\Domains\Laboratory\Domain\Model\LabTestDefinition;
use App\Shared\Domain\NotFound;

final class EloquentLabPanelReader implements LabPanelReader
{
    public function summary(string $panelId): LabPanelSummary
    {
        $panel = LabPanel::query()->whereKey($panelId)->first()
            ?? throw NotFound::aggregate(LabPanel::class, $panelId);

        return $this->toSummary($panel);
    }

    public function list(?bool $active = null): array
    {
        $query = LabPanel::query();

        if ($active !== null) {
            $query->where('active', $active);
        }

        return array_values(
            $query->orderBy('name')->orderBy('id')->get()
                ->map(fn (LabPanel $panel): LabPanelSummary => $this->toSummary($panel))
                ->all()
        );
    }

    private function toSummary(LabPanel $panel): LabPanelSummary
    {
        $members = LabPanelMember::query()
            ->where('panel_id', $panel->id)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();

        /** @var array<string, array{code: string, name: string}> $codes */
        $codes = [];
        foreach (LabTestDefinition::query()->whereIn('id', $members->pluck('test_definition_id')->all())->get() as $def) {
            $codes[(string) $def->id] = ['code' => (string) $def->code, 'name' => (string) $def->name];
        }

        $memberRows = $members->map(function (LabPanelMember $m) use ($codes): array {
            $def = $codes[(string) $m->test_definition_id] ?? ['code' => '', 'name' => ''];

            return [
                'testDefinitionId' => (string) $m->test_definition_id,
                'testCode' => $def['code'],
                'testName' => $def['name'],
                'sequence' => (int) $m->sequence,
            ];
        })->all();

        return new LabPanelSummary(
            panelId: (string) $panel->id,
            code: (string) $panel->code,
            name: (string) $panel->name,
            category: $panel->category,
            description: $panel->description,
            active: (bool) $panel->active,
            version: (int) $panel->version,
            members: array_values($memberRows),
        );
    }
}
