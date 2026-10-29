<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class PlatformSuperAdmin extends Model
{
    protected $table = 'platform_super_admins';

    /** @var list<string> */
    protected $guarded = [];
}
