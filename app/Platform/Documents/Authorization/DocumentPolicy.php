<?php

declare(strict_types=1);

namespace App\Platform\Documents\Authorization;

use App\Models\User;
use App\Platform\Documents\Domain\Model\Document;
use App\Shared\Authorization\BasePolicy;

final class DocumentPolicy extends BasePolicy
{
    public function view(User $user, ?Document $document = null): bool
    {
        if ($document !== null) {
            return $this->sameTenant($user, (string) $document->tenant_id)
                && $this->permits($user, 'documents.document.view');
        }

        return $this->coarse($user, 'documents.document.view');
    }

    public function download(User $user, ?Document $document = null): bool
    {
        if ($document !== null) {
            return $this->sameTenant($user, (string) $document->tenant_id)
                && $this->permits($user, 'documents.document.download');
        }

        return $this->coarse($user, 'documents.document.download');
    }

    public function upload(User $user): bool
    {
        return $this->coarse($user, 'documents.document.upload');
    }

    public function generate(User $user): bool
    {
        return $this->coarse($user, 'documents.document.generate');
    }

    public function sign(User $user, ?Document $document = null): bool
    {
        if ($document !== null) {
            return $this->sameTenant($user, (string) $document->tenant_id)
                && $this->permits($user, 'documents.document.sign');
        }

        return $this->coarse($user, 'documents.document.sign');
    }

    public function redact(User $user, ?Document $document = null): bool
    {
        if ($document !== null) {
            return $this->sameTenant($user, (string) $document->tenant_id)
                && $this->permits($user, 'documents.document.redact');
        }

        return $this->coarse($user, 'documents.document.redact');
    }

    public function dispose(User $user, ?Document $document = null): bool
    {
        if ($document !== null) {
            return $this->sameTenant($user, (string) $document->tenant_id)
                && $this->permits($user, 'documents.document.dispose');
        }

        return $this->coarse($user, 'documents.document.dispose');
    }

    public function manageTemplate(User $user): bool
    {
        return $this->coarse($user, 'documents.template.manage');
    }

    public function manageRetention(User $user): bool
    {
        return $this->coarse($user, 'documents.retention.manage');
    }

    public function manageGrant(User $user): bool
    {
        return $this->coarse($user, 'documents.grant.manage');
    }

    private function coarse(User $user, string $key): bool
    {
        return $this->context->hasTenant()
            && $this->permits($user, $key);
    }
}
