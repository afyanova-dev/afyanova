<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Presentation\Http\Controller;

use App\Domains\Procurement\Application\Command\SubmitPurchaseRequisition;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * DRAFT → SUBMITTED. The bus checks `procurement.requisitions.create` at the request's own facility.
 */
final class SubmitPurchaseRequisitionController
{
    public function __construct(private readonly CommandBus $commands) {}

    public function __invoke(Request $request, string $requisitionId): RedirectResponse
    {
        abort_unless(Str::isUuid($requisitionId), 404);

        $user = $request->user();

        if ($user === null) {
            abort(401);
        }

        try {
            $this->commands->dispatch(new SubmitPurchaseRequisition($requisitionId, (string) $user->id));
        } catch (DomainException|InvalidArgumentException $exception) {
            return back()->withErrors(['request' => $exception->getMessage()]);
        }

        return back()->with('status', 'Request submitted for approval.');
    }
}
