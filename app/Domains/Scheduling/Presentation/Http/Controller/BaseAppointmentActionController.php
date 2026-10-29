<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Presentation\Http\Controller;

use App\Domains\Scheduling\Contracts\Dto\AppointmentSummary;
use App\Domains\Scheduling\Contracts\SchedulingApi;
use App\Models\User;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

abstract class BaseAppointmentActionController
{
    protected function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    protected function facilityId(TenantContext $context): string
    {
        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');

        return $facilityId;
    }

    /**
     * Load an appointment scoped to the active facility. Anything else is a
     * 404 — a forged id from another facility must not be distinguishable.
     */
    protected function appointment(SchedulingApi $schedulingApi, string $facilityId, string $appointmentId): AppointmentSummary
    {
        $appointment = $schedulingApi->getAppointmentSummary($appointmentId);

        if ($appointment === null || $appointment->facilityId !== $facilityId) {
            abort(404);
        }

        return $appointment;
    }

    protected function done(Request $request, string $message): RedirectResponse
    {
        if ($request->filled('return_to')) {
            $returnTo = (string) $request->input('return_to');
            if (str_starts_with($returnTo, '/') && ! str_starts_with($returnTo, '//')) {
                return redirect($returnTo)->with('status', $message);
            }
        }

        return redirect()->route('reception.index')->with('status', $message);
    }

    protected function fail(string $key, string $message): RedirectResponse
    {
        return back()->withErrors([$key => $message]);
    }

    protected function domainError(\Throwable $e, string $key, string $context, array $extra = []): RedirectResponse
    {
        if ($e instanceof \DomainException) {
            return $this->fail($key, $e->getMessage());
        }

        Log::warning($context, ['error' => $e->getMessage()] + $extra);

        return $this->fail($key, 'The action could not be completed. Please try again.');
    }
}
