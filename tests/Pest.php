<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Pest bootstrap  (IMPLEMENTATION-CONTRACTS.md §17)
|--------------------------------------------------------------------------
| Feature tests hit the real PostgreSQL test database (afyanovav4_test) —
| never SQLite (§17.1). Unit tests are plain.
*/

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Unit');
pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeUuid', fn () => $this->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i'));
