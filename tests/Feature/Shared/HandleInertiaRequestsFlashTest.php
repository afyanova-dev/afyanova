<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use App\Shared\Http\Middleware\HandleInertiaRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

final class HandleInertiaRequestsFlashTest extends TestCase
{
    public function test_generates_stable_uuid_when_status_is_flashed(): void
    {
        $request = Request::create('/test', 'GET');
        $session = app('session.store');
        $session->start();
        $session->flash('status', 'Patient saved successfully');
        $request->setLaravelSession($session);

        $middleware = app(HandleInertiaRequests::class);
        $shared1 = $middleware->share($request);

        $this->assertIsArray($shared1['flash']);
        $flashId1 = $shared1['flash']['id'];
        $this->assertNotNull($flashId1);
        $this->assertTrue(Str::isUuid($flashId1));
        $this->assertSame('Patient saved successfully', ($shared1['flash']['status'])());
        $this->assertNull(($shared1['flash']['error'])());

        // Repeated reads within the same request lifecycle yield the same stable UUID (Decision D3)
        $shared2 = $middleware->share($request);
        $this->assertSame($flashId1, $shared2['flash']['id']);
    }

    public function test_generates_uuid_when_error_is_flashed(): void
    {
        $request = Request::create('/test', 'GET');
        $session = app('session.store');
        $session->start();
        $session->flash('error', 'The record was modified concurrently');
        $request->setLaravelSession($session);

        $middleware = app(HandleInertiaRequests::class);
        $shared = $middleware->share($request);

        $this->assertIsArray($shared['flash']);
        $this->assertNotNull($shared['flash']['id']);
        $this->assertTrue(Str::isUuid($shared['flash']['id']));
        $this->assertNull(($shared['flash']['status'])());
        $this->assertSame('The record was modified concurrently', ($shared['flash']['error'])());
    }

    public function test_flash_id_is_null_when_no_flash_data_is_present(): void
    {
        $request = Request::create('/test', 'GET');
        $session = app('session.store');
        $session->start();
        $request->setLaravelSession($session);

        $middleware = app(HandleInertiaRequests::class);
        $shared = $middleware->share($request);

        $this->assertIsArray($shared['flash']);
        $this->assertNull($shared['flash']['id']);
        $this->assertNull(($shared['flash']['status'])());
        $this->assertNull(($shared['flash']['error'])());
    }

    public function test_preserves_pre_existing_flash_id_if_present_in_session(): void
    {
        $existingId = (string) Str::uuid();
        $request = Request::create('/test', 'GET');
        $session = app('session.store');
        $session->start();
        $session->flash('flash_id', $existingId);
        $session->flash('status', 'Action succeeded');
        $request->setLaravelSession($session);

        $middleware = app(HandleInertiaRequests::class);
        $shared = $middleware->share($request);

        $this->assertSame($existingId, $shared['flash']['id']);
    }

    public function test_info_flash_is_shared_with_stable_id(): void
    {
        $request = Request::create('/test', 'GET');
        $session = app('session.store');
        $session->start();
        $session->flash('info', 'No changes detected.');
        $request->setLaravelSession($session);

        $shared = app(HandleInertiaRequests::class)->share($request);

        $this->assertNotNull($shared['flash']['id']);
        $this->assertSame('No changes detected.', ($shared['flash']['info'])());
    }

    public function test_pending_walkin_values_are_shared(): void
    {
        $request = Request::create('/test', 'GET');
        $session = app('session.store');
        $session->start();
        $session->flash('pending_walkin_values', ['given_name' => 'Neema']);
        $request->setLaravelSession($session);

        $shared = app(HandleInertiaRequests::class)->share($request);

        $this->assertSame(['given_name' => 'Neema'], ($shared['flash']['pending_walkin_values'])());
    }
}
