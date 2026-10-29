<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Presentation\Http\Controller;

use App\Domains\Procurement\Application\Command\BlacklistSupplier;
use App\Domains\Procurement\Application\Command\ChangeSupplierStatus;
use App\Domains\Procurement\Domain\ValueObject\SupplierStatus;
use App\Domains\Procurement\Presentation\Http\Request\ChangeSupplierStatusRequest;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ChangeSupplierStatusController
{
    public function __construct(private readonly CommandBus $commands) {}

    public function __invoke(ChangeSupplierStatusRequest $request, string $supplierId): RedirectResponse
    {
        abort_unless(Str::isUuid($supplierId), 404);

        $data = $request->validated();
        $targetStatus = (string) $data['target_status'];
        $reason = (string) $data['reason'];
        $user = $request->user();

        if ($user === null) {
            abort(401);
        }

        $actorId = (string) $user->id;

        try {
            $this->commands->dispatch($targetStatus === SupplierStatus::BLACKLISTED
                ? new BlacklistSupplier($supplierId, $actorId, $reason)
                : new ChangeSupplierStatus($supplierId, $actorId, $targetStatus, $reason));
        } catch (DomainException|InvalidArgumentException $exception) {
            return back()->withErrors(['supplier' => $exception->getMessage()]);
        }

        return back()->with('status', $targetStatus === SupplierStatus::BLACKLISTED
            ? 'Supplier blacklisted. This cannot be undone.'
            : 'Supplier status updated.');
    }
}
