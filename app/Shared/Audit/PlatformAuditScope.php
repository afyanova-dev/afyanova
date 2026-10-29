<?php

declare(strict_types=1);

namespace App\Shared\Audit;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class PlatformAuditScope
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function run(string $actorId, Closure $callback): mixed
    {
        return DB::transaction(static function () use ($actorId, $callback): mixed {
            $authorized = DB::table('platform_super_admins as platform_admin')
                ->join('users', 'users.id', '=', 'platform_admin.user_id')
                ->where('platform_admin.user_id', $actorId)
                ->where('platform_admin.status', 'ACTIVE')
                ->whereNull('users.tenant_id')
                ->where('users.status', 'ACTIVE')
                ->exists();

            if (! $authorized) {
                throw new AuthorizationException('Active Platform Super Admin access is required.');
            }

            DB::statement("select set_config('app.platform_admin_id', ?, true)", [$actorId]);

            return $callback();
        });
    }
}
