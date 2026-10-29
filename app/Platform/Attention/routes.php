<?php

declare(strict_types=1);

use App\Platform\Attention\Presentation\Http\Controller\AttentionBoardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified'])->group(function (): void {
    Route::get('/workspaces/attention', AttentionBoardController::class)->name('attention.index');
});
