<?php

declare(strict_types=1);

namespace App\Domains\Identity\Domain\Model;

use App\Domains\Identity\Domain\Enum\PermissionLayer;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Global permission catalogue (docs/00-foundation/03-identity-rbac.md §9, §29, §66). No tenant scope. */
class Permission extends Model
{
    use HasUuids;

    protected $table = 'idn_permissions';

    /** @var list<string> */
    protected $fillable = ['key', 'layer', 'description'];

    protected $casts = ['layer' => PermissionLayer::class];
}
