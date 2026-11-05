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
            return $this->canAccessDocument($user, $document, 'documents.document.view');
        }

        return $this->coarse($user, 'documents.document.view');
    }

    public function download(User $user, ?Document $document = null): bool
    {
        if ($document !== null) {
            return $this->canAccessDocument($user, $document, 'documents.document.download');
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
            return $this->canAccessDocument($user, $document, 'documents.document.sign');
        }

        return $this->coarse($user, 'documents.document.sign');
    }

    public function redact(User $user, ?Document $document = null): bool
    {
        if ($document !== null) {
            return $this->canAccessDocument($user, $document, 'documents.document.redact');
        }

        return $this->coarse($user, 'documents.document.redact');
    }

    public function dispose(User $user, ?Document $document = null): bool
    {
        if ($document !== null) {
            return $this->canAccessDocument($user, $document, 'documents.document.dispose');
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

    private function canAccessDocument(User $user, Document $document, string $permission): bool
    {
        $facilityId = $this->context->facilityId();
        $withinActiveFacility = $facilityId !== null
            ? $this->inActiveFacility((string) $document->facility_id)
            : ($this->context->isGeneral()
                && $this->inFacilityScope($user, (string) $document->facility_id));

        return $this->sameTenant($user, (string) $document->tenant_id)
            && $withinActiveFacility
            && $this->permits($user, $permission);
    }
}
