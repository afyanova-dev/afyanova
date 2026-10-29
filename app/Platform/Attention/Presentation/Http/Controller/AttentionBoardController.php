<?php

declare(strict_types=1);

namespace App\Platform\Attention\Presentation\Http\Controller;

use App\Models\User;
use App\Platform\Attention\Application\AttentionBoardPresenter;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AttentionBoardController
{
    public function __invoke(
        Request $request,
        TenantContext $context,
        AttentionBoardPresenter $presenter,
    ): Response {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');
        abort_unless($user->can('patient.patients.read'), 403, 'You do not have access to attention lists.');

        $scope = strtolower(trim((string) $request->query('scope', 'all')));
        $mineOnly = $scope === 'mine';

        return Inertia::render('Attention/Index', array_merge(
            $presenter->present($facilityId, (string) $user->id, $mineOnly),
            ['scope' => $mineOnly ? 'mine' : 'all'],
        ));
    }
}
