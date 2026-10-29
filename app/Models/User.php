<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Authorization\OrganizationPermissionScope;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Tenancy\TenantContext;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * The platform identity (docs/00-foundation/03-identity-rbac.md §3). Kept at
 * `app/Models/User.php` for framework compatibility (IC §2) but conceptually
 * `App\Domains\Identity`.
 *
 * NOT tenant-scoped by RLS or a global scope: authentication resolves the user
 * (and thus the tenant) before any tenant context exists. Email is globally
 * unique in PHASE 2.
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasUuids;
    use Notifiable;
    use TwoFactorAuthenticatable;

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'name', 'email', 'password'];

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'ACTIVE';
    }

    public function isPlatformSuperAdmin(): bool
    {
        return $this->isActive()
            && $this->tenant_id === null
            && PlatformSuperAdmin::query()
                ->where('user_id', $this->getKey())
                ->where('status', 'ACTIVE')
                ->exists();
    }

    /**
     * Does the user hold this permission key in the current facility context?
     * Resolved through {@see ScopeChecker} against `idn_access_assignments`; the
     * active facility comes from {@see TenantContext}, never the request.
     */
    public function hasPermissionKey(string $key): bool
    {
        $context = app(TenantContext::class);
        $facilityId = $context->facilityId();
        $scope = app(ScopeChecker::class);

        if ($facilityId !== null) {
            return $scope->hasFacilityPermission($this, $facilityId, $key);
        }

        $permissions = $scope->effectivePermissions($this, null);
        if ($context->isGeneral()) {
            $permissions = [...$permissions, ...app(OrganizationPermissionScope::class)->permissions($this)];
        }
        if (in_array($key, $permissions, true)) {
            return true;
        }

        return false;
    }
}
