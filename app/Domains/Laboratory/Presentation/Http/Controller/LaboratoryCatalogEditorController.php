<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Presentation\Http\Controller;

use App\Domains\Identity\Contracts\IdentityApi;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

final class LaboratoryCatalogEditorController
{
    public function __construct(
        private readonly IdentityApi $identity,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizeEditorManagement($request);

        return Inertia::render('Admin/Identity/LaboratoryCatalogEditors', [
            'candidates' => $this->identity->laboratoryCatalogEditorCandidates($this->context->tenantId()),
        ]);
    }

    public function grant(Request $request, string $user): RedirectResponse
    {
        $this->authorizeEditorManagement($request);
        $actorId = (string) ($request->user() ?? throw new AuthenticationException)->getAuthIdentifier();

        try {
            $this->identity->grantLaboratoryCatalogEditor($this->context->tenantId(), $actorId, $user);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['user' => $exception->getMessage()]);
        }

        return back()->with('status', 'Laboratory catalog editor access granted.');
    }

    public function revoke(Request $request, string $user): RedirectResponse
    {
        $this->authorizeEditorManagement($request);
        $actorId = (string) ($request->user() ?? throw new AuthenticationException)->getAuthIdentifier();

        $this->identity->revokeLaboratoryCatalogEditor($this->context->tenantId(), $actorId, $user);

        return back()->with('status', 'Laboratory catalog editor access revoked.');
    }

    private function authorizeEditorManagement(Request $request): void
    {
        $actor = $request->user();
        abort_unless(
            $actor !== null
                && (string) $actor->tenant_id === $this->context->tenantId()
                && $this->identity->isTenantAdmin($this->context->tenantId(), (string) $actor->getKey()),
            403,
        );
    }
}
