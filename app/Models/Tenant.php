<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The platform tenant registry (docs/00-foundation/05-tenant-isolation.md §2,
 * ADR-001). Kept next to {@see User} as part of the identity spine that the
 * framework must read before any tenant context exists — hence NO RLS, NO
 * global scope. PHASE 3 owns the tenant lifecycle (suspension, decommissioning).
 */
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'shared_tenants';

    /** @var list<string> */
    protected $fillable = ['name', 'slug', 'status'];

    public function isActive(): bool
    {
        return $this->status === 'ACTIVE';
    }
}
