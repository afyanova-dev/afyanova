<?php

declare(strict_types=1);

namespace App\Domains\Authentication\Presentation\Http\Controller;

use App\Domains\Authentication\Infrastructure\Sessions;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Switching the active facility context (docs/00-foundation/04-authentication.md
 * §31; docs/00-foundation/03-identity-rbac.md §14, §56; UI-UX-CONTRACT §22).
 *
 * Facility selection is a *working context*, never authorization. The target
 * must be one the user is granted (`idn_user_facility_access`); a value outside
 * that set is rejected — never used to widen scope (docs 03 §56).
 */
final class FacilityContextController
{
    public function update(Request $request, ScopeChecker $scope, Sessions $sessions): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        if ($request->input('mode') === 'general') {
            abort_unless($scope->canUseGeneralContext($user), 403, 'You are not authorized to use General context.');
            $request->session()->forget('active_facility_id');
            $request->session()->put('facility_context_mode', 'general');

            return back()->with('status', 'Working context: General.');
        }

        $facilityId = (string) $request->input('facility_id');

        abort_unless(
            in_array($facilityId, $scope->selectableFacilities($user), true),
            403,
            'You are not assigned to that facility.',
        );

        $request->session()->put('active_facility_id', $facilityId);
        $request->session()->put('facility_context_mode', 'facility');

        return back()->with('status', __('Active facility: :name', ['name' => $sessions->facilityName($facilityId)]));
    }
}
