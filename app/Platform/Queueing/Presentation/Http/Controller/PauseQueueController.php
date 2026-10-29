<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Presentation\Http\Controller;

use App\Platform\Queueing\Contracts\QueueingApi;
use App\Platform\Queueing\Presentation\Http\Request\ManageQueueRequest;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

final class PauseQueueController extends BaseQueueActionController
{
    public function __invoke(
        ManageQueueRequest $request,
        TenantContext $context,
        QueueingApi $queueingApi,
        string $queue,
    ): RedirectResponse {
        $this->actor($request);
        $facilityId = $this->facilityId($context);

        $reason = $request->filled('reason') ? trim((string) $request->input('reason')) : null;
        if ($reason === null || $reason === '') {
            return $this->fail('reason', 'A reason is required to pause a queue.');
        }

        $target = $this->queue($facilityId, $queue);
        Gate::authorize('manage', $target);

        try {
            $paused = $queueingApi->pauseQueue($target->id, $reason);
        } catch (\Throwable $e) {
            return $this->domainError($e, 'pause', 'Queue could not be paused.', ['queue_id' => $target->id]);
        }

        return $this->done($request, "Queue {$paused->name} paused.");
    }
}
