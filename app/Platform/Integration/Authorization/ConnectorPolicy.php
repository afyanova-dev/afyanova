<?php

declare(strict_types=1);

namespace App\Platform\Integration\Authorization;

use App\Models\User;
use App\Platform\Integration\Domain\Model\IntegrationConnector;
use App\Shared\Authorization\BasePolicy;

final class ConnectorPolicy extends BasePolicy
{
    public function view(User $user, ?IntegrationConnector $connector = null): bool
    {
        if ($connector !== null) {
            return $this->sameTenant($user, (string) $connector->tenant_id)
                && $this->permits($user, 'integration.connector.manage');
        }

        return $this->coarse($user, 'integration.connector.manage');
    }

    public function manage(User $user, ?IntegrationConnector $connector = null): bool
    {
        if ($connector !== null) {
            return $this->sameTenant($user, (string) $connector->tenant_id)
                && $this->permits($user, 'integration.connector.manage');
        }

        return $this->coarse($user, 'integration.connector.manage');
    }

    public function rotateCredential(User $user, ?IntegrationConnector $connector = null): bool
    {
        if ($connector !== null) {
            return $this->sameTenant($user, (string) $connector->tenant_id)
                && $this->permits($user, 'integration.credential.rotate');
        }

        return $this->coarse($user, 'integration.credential.rotate');
    }

    public function configureWebhook(User $user, ?IntegrationConnector $connector = null): bool
    {
        if ($connector !== null) {
            return $this->sameTenant($user, (string) $connector->tenant_id)
                && $this->permits($user, 'integration.webhook.manage');
        }

        return $this->coarse($user, 'integration.webhook.manage');
    }

    private function coarse(User $user, string $key): bool
    {
        return $this->context->hasTenant()
            && $this->permits($user, $key);
    }
}
