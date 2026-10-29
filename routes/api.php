<?php

declare(strict_types=1);

use App\Shared\Http\ApiResponse;
use Illuminate\Support\Facades\Route;

/*
 | Machine-facing API — mounted at /api/v1 (bootstrap/app.php `apiPrefix`).
 | Stateless; integration auth is separate from human auth (IC §15, doc 30).
 | Per-connector routes are added by the Integration platform service (PHASE 12+).
 */

Route::get('/ping', fn () => ApiResponse::ok(['pong' => true]))->name('api.ping');
