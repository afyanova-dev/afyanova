<?php

declare(strict_types=1);

namespace App\Platform\Integration\Authorization;

use App\Models\User;
use App\Platform\Integration\Domain\Model\FileImport;
use App\Shared\Authorization\BasePolicy;

final class FileImportPolicy extends BasePolicy
{
    public function reconcile(User $user, ?FileImport $fileImport = null): bool
    {
        if ($fileImport !== null) {
            return $this->sameTenant($user, (string) $fileImport->tenant_id)
                && $this->permits($user, 'integration.import.reconcile');
        }

        return $this->coarse($user, 'integration.import.reconcile');
    }

    private function coarse(User $user, string $key): bool
    {
        return $this->context->hasTenant()
            && $this->permits($user, $key);
    }
}
