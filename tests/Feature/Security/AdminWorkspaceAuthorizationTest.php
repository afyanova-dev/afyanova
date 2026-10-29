<?php

declare(strict_types=1);

use App\Models\User;
use App\Shared\Http\Middleware\RequireAdminWorkspace;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

function runAdminWorkspaceMiddleware(User $user, string $tenantId, string $facilityId): Response
{
    $request = Request::create('/workspaces/admin');
    $request->setUserResolver(static fn (): User => $user);

    return TenantContextScope::run(
        $tenantId,
        $facilityId,
        fn () => app(RequireAdminWorkspace::class)->handle($request, fn () => response('allowed')),
    );
}

function adminWorkspaceStatus(User $user, string $tenantId, string $facilityId): int
{
    try {
        return runAdminWorkspaceMiddleware($user, $tenantId, $facilityId)->getStatusCode();
    } catch (HttpException $exception) {
        return $exception->getStatusCode();
    }
}

it('requires the trusted tenant context to be established before checking Admin permissions', function (): void {
    ['user' => $user] = makeTenantWithUser(['admin.workspace.access']);
    $user->forceFill([
        'two_factor_secret' => 'encrypted-secret',
        'two_factor_confirmed_at' => now(),
    ]);
    $request = Request::create('/workspaces/admin');
    $request->setUserResolver(static fn (): User => $user);

    try {
        $status = app(RequireAdminWorkspace::class)
            ->handle($request, fn () => response('allowed'))
            ->getStatusCode();
    } catch (HttpException $exception) {
        $status = $exception->getStatusCode();
    }

    expect($status)->toBe(403);
});

it('requires the dedicated Admin workspace permission', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = makeTenantWithUser();
    $user->forceFill([
        'two_factor_secret' => 'encrypted-secret',
        'two_factor_confirmed_at' => now(),
    ]);

    expect(adminWorkspaceStatus($user, $tenant->id, $facility->id))->toBe(403);
});

it('requires confirmed two-factor authentication for Admin access', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = makeTenantWithUser(['admin.workspace.access']);

    expect(adminWorkspaceStatus($user, $tenant->id, $facility->id))->toBe(302);
});

it('allows Admin access only when permission and confirmed two-factor authentication are present', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = makeTenantWithUser(['admin.workspace.access']);
    $user->forceFill([
        'two_factor_secret' => 'encrypted-secret',
        'two_factor_confirmed_at' => now(),
    ]);

    $response = runAdminWorkspaceMiddleware($user, $tenant->id, $facility->id);

    expect($response->getContent())->toBe('allowed');
});
