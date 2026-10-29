<?php

declare(strict_types=1);

namespace App\Domains\Authentication\Application\Fortify;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;
use Laravel\Fortify\Fortify;

final class DashboardTwoFactorLoginResponse implements TwoFactorLoginResponse
{
    public function toResponse($request)
    {
        $request->session()->forget('url.intended');

        return $request->wantsJson()
            ? new JsonResponse('', 204)
            : redirect()->to(Fortify::redirects('login'));
    }
}
