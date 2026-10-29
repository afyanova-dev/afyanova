<?php

declare(strict_types=1);

namespace App\Domains\Authentication\Console;

use App\Domains\Authentication\Application\FacilityInvitationExpiryService;
use Illuminate\Console\Command;

final class ExpireFacilityInvitationsCommand extends Command
{
    protected $signature = 'identity:expire-facility-invitations
                            {--once : Process one sweep and exit}';

    protected $description = 'Expire lapsed facility setup invitations and notify HR / identity managers.';

    public function handle(FacilityInvitationExpiryService $expiry): int
    {
        $result = $expiry->run();

        if ($result['expired'] > 0 || $result['notified'] > 0) {
            $this->info(sprintf(
                'Expired %d facility invitation(s); sent %d expiry alert(s).',
                $result['expired'],
                $result['notified'],
            ));
        }

        return self::SUCCESS;
    }
}
