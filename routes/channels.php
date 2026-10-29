<?php

declare(strict_types=1);

use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use Illuminate\Support\Facades\Broadcast;

/*
 | Broadcast channel authorization (IMPLEMENTATION-CONTRACTS.md §22.3; UI-UX-CONTRACT §19).
 | Real-time channels are tenant-and-facility-scoped private channels authorized through
 | the same policy layer as HTTP. No wildcard channel routes are permitted.
 */

Broadcast::channel('App.Models.User.{id}', function ($user, string $id): bool {
    return $user instanceof User && (string) $user->id === $id;
});

Broadcast::channel('facility.{facilityId}.calling-board', function ($user, string $facilityId): bool {
    if (! $user instanceof User) {
        return false;
    }

    $scope = app(ScopeChecker::class);

    return $scope->withinFacilityScope($user, $facilityId);
});

Broadcast::channel('facility.{facilityId}.reception', function ($user, string $facilityId): bool {
    if (! $user instanceof User) {
        return false;
    }

    $scope = app(ScopeChecker::class);
    if (! $scope->withinFacilityScope($user, $facilityId)) {
        return false;
    }

    $permissions = $scope->effectivePermissions($user, $facilityId);

    return in_array('encounter.encounters.read', $permissions, true)
        || in_array('sch.access', $permissions, true);
});

Broadcast::channel('facility.{facilityId}.queue.{queueId}', function ($user, string $facilityId, string $queueId): bool {
    if (! $user instanceof User) {
        return false;
    }

    $scope = app(ScopeChecker::class);
    if (! $scope->withinFacilityScope($user, $facilityId)) {
        return false;
    }

    $permissions = $scope->effectivePermissions($user, $facilityId);

    return in_array('queue.access', $permissions, true)
        || in_array('queue.ticket.view', $permissions, true);
});
