import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';
import type { FullConfig } from '@playwright/test';

export default function globalSetup(_config: FullConfig): void {
  const projectRoot = resolve(import.meta.dirname, '../..');
  const environment = { ...process.env, APP_ENV: 'testing', SESSION_DRIVER: 'file' };
  const assertion = [
    "require 'vendor/autoload.php';",
    "$app = require 'bootstrap/app.php';",
    "$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();",
    "$environment = $app->environment();",
    "$connection = config('database.default');",
    "$database = config(\"database.connections.{$connection}.database\");",
    "$sessionDriver = config('session.driver');",
    "if ($environment !== 'testing') { fwrite(STDERR, 'E2E requires APP_ENV=testing.'); exit(1); }",
    "if (!is_string($database) || !preg_match('/(?:_test|_testing)$/i', $database)) { fwrite(STDERR, 'E2E database name must end in _test or _testing.'); exit(1); }",
    "if (!in_array($sessionDriver, ['file', 'database'], true)) { fwrite(STDERR, 'E2E requires persistent session storage.'); exit(1); }",
    "fwrite(STDOUT, \"Using isolated E2E database ({$connection}) and persistent sessions.\\n\");",
  ].join(' ');

  execFileSync('php', ['-r', assertion], {
    cwd: projectRoot,
    env: environment,
    stdio: 'inherit',
  });
  execFileSync('php', ['artisan', 'db:seed', '--env=testing'], {
    cwd: projectRoot,
    env: environment,
    stdio: 'inherit',
  });
}
