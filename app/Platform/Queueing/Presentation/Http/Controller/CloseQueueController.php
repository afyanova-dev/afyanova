<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Presentation\Http\Controller;

use App\Platform\Queueing\Contracts\QueueingApi;
use App\Platform\Queueing\Presentation\Http\Request\ManageQueueRequest;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

final class CloseQueueController extends BaseQueueActionController
{
    public function __invoke(
        ManageQueueRequest $request,
        TenantContext $context,
        QueueingApi $queueingApi,
        string $queue,
    ): RedirectResponse {
        $this->actor($request);
        $facilityId = $this->facilityId($context);

        $target = $this->queue($facilityId, $queue);
        Gate::authorize('manage', $target);

        try {
            $closed = $queueingApi->closeQueue(
                $target->id,
                $request->filled('reason') ? trim((string) $request->input('reason')) : null,
            );
        } catch (\Throwable $e) {
            return $this->domainError($e, 'close', 'Queue could not be closed.', ['queue_id' => $target->id]);
        }

        return $this->done($request, "Queue {$closed->name} closed.");
    }
}
