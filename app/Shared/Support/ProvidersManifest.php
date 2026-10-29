<?php

declare(strict_types=1);

namespace App\Shared\Support;

/**
 * Asserts every scaffolded module has its service provider registered in
 * bootstrap/providers.php — a scaffolded-but-unregistered module is a hard
 * failure, never a silent skip (IMPLEMENTATION-CONTRACTS.md §2.4).
 */
final class ProvidersManifest
{
    /**
     * @return list<string> module paths that exist on disk but are not registered
     */
    public static function unregistered(string $basePath): array
    {
        $registered = require $basePath.'/bootstrap/providers.php';
        $registered = array_map(strval(...), $registered);

        $missing = [];

        foreach (['Domains', 'Platform'] as $tier) {
            $dir = $basePath.'/app/'.$tier;

            if (! is_dir($dir)) {
                continue;
            }

            foreach (glob($dir.'/*', GLOB_ONLYDIR) ?: [] as $modulePath) {
                $module = basename($modulePath);
                $provider = "App\\{$tier}\\{$module}\\{$module}ServiceProvider";

                if (! in_array($provider, $registered, true)) {
                    $missing[] = $provider;
                }
            }
        }

        return $missing;
    }
}
