<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware;

use App\Models\User;
use App\Shared\Authorization\OrganizationPermissionScope;
use App\Shared\Authorization\PermissionRegistry;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Support\CorrelationId;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Middleware;

/**
 * Shared Inertia props (IMPLEMENTATION-CONTRACTS.md §16; UI-UX-CONTRACT §20–§22).
 *
 * `auth.can` is the permission map the frontend reads for permission-aware UX —
 * the backend policy remains the security control. `auth.facilities` /
 * `auth.activeFacility` drive the FacilitySwitcher (UI-UX §22).
 * `flash.id` is a stable server-generated UUID providing exactly-once toast consumption.
 */
final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $hasFlash = $request->session()->has('status') || $request->session()->has('error') || $request->session()->has('success') || $request->session()->has('info');
        $flashId = null;

        if ($hasFlash) {
            $existingId = $request->session()->get('flash_id');
            if (is_string($existingId) && $existingId !== '') {
                $flashId = $existingId;
            } else {
                $flashId = (string) Str::uuid();
                $request->session()->flash('flash_id', $flashId);
            }
        }

        return [
            ...parent::share($request),
            'app' => ['name' => config('app.name')],
            'auth' => fn (): array => $this->auth($request),
            'flash' => [
                'id' => $flashId,
                'status' => fn () => $request->session()->get('status') ?? $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'info' => fn () => $request->session()->get('info'),
                'registered_patient' => fn () => $request->session()->get('registered_patient'),
                'duplicate_collision' => fn () => $request->session()->get('duplicate_collision'),
                'duplicate_review' => fn () => $request->session()->get('duplicate_review'),
                'pending_walkin_values' => fn () => $request->session()->get('pending_walkin_values'),
                'recorded_payment_id' => fn () => $request->session()->get('recorded_payment_id'),
            ],
            'correlationId' => fn () => CorrelationId::get(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function auth(Request $request): array
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return [
                'user' => null,
                'roles' => [],
                'can' => (object) [],
                'facilities' => [],
                'activeFacility' => null,
                'contextMode' => 'unresolved',
                'canUseGeneral' => false,
                'isPlatformSuperAdmin' => false,
            ];
        }

        $context = app(TenantContext::class);
        $scope = app(ScopeChecker::class);
        $activeFacility = $context->hasTenant() ? $context->facilityId() : null;

        $held = $scope->effectivePermissions($user, $activeFacility);
        $roles = $context->hasTenant() ? $scope->effectiveRoles($user, $activeFacility) : [];
        if ($activeFacility !== null) {
            $departmentIds = DB::table('org_departments')
                ->where('facility_id', $activeFacility)
                ->where('status', 'ACTIVE')
                ->pluck('id')
                ->map(static fn ($id): string => (string) $id)
                ->all();

            foreach ($departmentIds as $departmentId) {
                $held = [...$held, ...$scope->effectivePermissions($user, $activeFacility, $departmentId)];
                $roles = [...$roles, ...$scope->effectiveRoles($user, $activeFacility, $departmentId)];
            }
            $held = array_values(array_unique($held));
            $roles = array_values(array_unique($roles));
        }
        if ($context->isGeneral()) {
            $held = [...$held, ...app(OrganizationPermissionScope::class)->permissions($user)];
        }
        $can = [];
        foreach (PermissionRegistry::all() as $key) {
            $can[$key] = in_array($key, $held, true);
        }

        $facilities = $context->hasTenant()
            ? DB::table('org_facilities')
                ->whereIn('id', $scope->selectableFacilities($user))
                ->get(['id', 'name'])
                ->map(static fn ($f): array => ['id' => (string) $f->id, 'name' => (string) $f->name])
                ->all()
            : [];

        return [
            'user' => [
                'id' => $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $roles,
            ],
            'roles' => $roles,
            'can' => $can,
            'facilities' => $facilities,
            'activeFacility' => $activeFacility,
            'contextMode' => $context->isGeneral() ? 'general' : ($activeFacility !== null ? 'facility' : 'unresolved'),
            'canUseGeneral' => $scope->canUseGeneralContext($user),
            'isPlatformSuperAdmin' => $user->isPlatformSuperAdmin(),
        ];
    }
}
