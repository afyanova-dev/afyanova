<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Presentation\Http\Controller;

use App\Domains\Encounter\Presentation\Http\Request\TransferEncounterRequest;
use App\Models\User;
use App\Platform\Queueing\Contracts\DTO\TransferQueueDto;
use App\Platform\Queueing\Contracts\QueueingApi;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

final class EncounterTransferController
{
    public function __invoke(
        TransferEncounterRequest $request,
        TenantContext $context,
        QueueingApi $queueingApi,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');

        $encounterId = (string) $request->input('encounter_id');
        $destinationQueueId = (string) $request->input('queue_id');
        $comment = $request->filled('comment') ? trim((string) $request->input('comment')) : null;

        try {
            $entry = $queueingApi->getActiveForEncounter($encounterId);
        } catch (\Throwable $e) {
            Log::warning('Queue transfer lookup failed', ['encounter_id' => $encounterId, 'error' => $e->getMessage()]);

            return back()->withErrors(['transfer' => 'Could not find an active queue entry for this encounter.']);
        }

        if ($entry === null) {
            return back()->withErrors(['transfer' => 'This encounter has no active queue entry to move.']);
        }

        if ($entry->queueId === $destinationQueueId) {
            return back()->withErrors(['queue_id' => 'The patient is already in the selected service location.']);
        }

        try {
            $moved = $queueingApi->transfer(new TransferQueueDto(
                entryId: $entry->id,
                destinationQueueId: $destinationQueueId,
                reason: $comment,
            ));
        } catch (\DomainException $e) {
            return back()->withErrors(['transfer' => $e->getMessage()]);
        } catch (\Throwable $e) {
            Log::warning('Queue transfer failed', ['encounter_id' => $encounterId, 'error' => $e->getMessage()]);

            return back()->withErrors(['transfer' => 'The move could not be completed.']);
        }

        $msg = "Patient moved successfully (ticket #{$moved->ticketNumber}).";

        if ($request->filled('return_to')) {
            $returnTo = (string) $request->input('return_to');
            if (str_starts_with($returnTo, '/') && ! str_starts_with($returnTo, '//')) {
                return redirect($returnTo)->with('status', $msg);
            }
        }

        return back()->with('status', $msg);
    }
}
