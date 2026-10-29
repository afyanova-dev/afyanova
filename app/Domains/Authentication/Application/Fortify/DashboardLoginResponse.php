<?php

declare(strict_types=1);

namespace App\Domains\Authentication\Application\Fortify;

use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Fortify;

final class DashboardLoginResponse implements LoginResponse
{
    public function toResponse($request)
    {
        $request->session()->forget('url.intended');

        return $request->wantsJson()
            ? response()->json(['two_factor' => false])
            : redirect()->to(Fortify::redirects('login'));
    }
}
