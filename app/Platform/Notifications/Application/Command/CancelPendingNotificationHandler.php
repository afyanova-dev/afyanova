<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Application\Command;

use App\Platform\Notifications\Domain\Exception\NotificationNotFoundException;
use App\Platform\Notifications\Domain\Model\NotificationStatus;
use App\Platform\Notifications\Domain\Model\SuppressionReason;
use App\Platform\Notifications\Domain\Repository\NotificationRequestRepositoryInterface;

final readonly class CancelPendingNotificationHandler
{
    public function __construct(private NotificationRequestRepositoryInterface $requestRepository) {}

    public function __invoke(CancelPendingNotificationCommand $command): void
    {
        $request = $this->requestRepository->findById($command->requestId);
        if ($request === null) {
            throw NotificationNotFoundException::forId($command->requestId);
        }

        if ($request->status === NotificationStatus::ACCEPTED || $request->status === NotificationStatus::RENDERED) {
            $request->markSuppressed(SuppressionReason::OPT_OUT, 'Cancelled by user or caller');
            $this->requestRepository->save($request);
        }
    }
}
