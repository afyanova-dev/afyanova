<?php

declare(strict_types=1);

namespace App\Shared\Tenancy;

/**
 * The closed set of operations permitted to bypass Row-Level Security
 * (IMPLEMENTATION-CONTRACTS.md §11.4). Nothing outside this enum compiles as a
 * `SystemContext::run()` argument.
 */
enum SystemOperation: string
{
    /** Schema migrations that create/alter tenant-scoped tables. */
    case Migration = 'migration';

    /** A small number of authenticated console commands for cross-tenant ops. */
    case OpsConsole = 'ops_console';
}
