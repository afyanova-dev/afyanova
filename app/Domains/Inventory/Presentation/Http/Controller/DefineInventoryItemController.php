<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Presentation\Http\Controller;

use App\Domains\Inventory\Application\Command\DefineInventoryItem;
use App\Domains\Inventory\Presentation\Http\Request\DefineInventoryItemRequest;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class DefineInventoryItemController
{
    public function __construct(private readonly CommandBus $commands) {}

    public function __invoke(DefineInventoryItemRequest $request): RedirectResponse
    {
        $data = $request->validated();

        try {
            $this->commands->dispatch(new DefineInventoryItem(
                itemId: (string) Str::uuid7(),
                actorId: (string) $request->user()->id,
                code: $data['code'],
                name: $data['name'],
                itemType: $data['item_type'],
                uom: $data['uom'],
                description: $data['description'] ?? null,
                category: $data['category'] ?? null,
                trackBatch: (bool) ($data['track_batch'] ?? false),
                trackSerial: (bool) ($data['track_serial'] ?? false),
                trackExpiry: (bool) ($data['track_expiry'] ?? false),
                isControlled: (bool) ($data['is_controlled'] ?? false),
                packUnit: $data['pack_unit'] ?? null,
                packSize: isset($data['pack_size']) ? (string) $data['pack_size'] : null,
            ));
        } catch (DomainException|InvalidArgumentException $exception) {
            return back()->withErrors(['item' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Inventory item added to the catalogue.');
    }
}
