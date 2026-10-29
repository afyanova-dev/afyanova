<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Headless Chrome Executable Path
    |--------------------------------------------------------------------------
    |
    | Path to the Chrome or Chromium binary used for headless PDF rendering.
    |
    */
    'chrome_path' => env('DOCUMENTS_CHROME_PATH', '/usr/bin/google-chrome'),

    /*
    |--------------------------------------------------------------------------
    | PDF Generation Timeout
    |--------------------------------------------------------------------------
    |
    | Maximum execution timeout in seconds for Chrome headless rendering.
    |
    */
    'timeout_seconds' => (int) env('DOCUMENTS_TIMEOUT_SECONDS', 30),

    /*
    |--------------------------------------------------------------------------
    | Antivirus Scanner Driver
    |--------------------------------------------------------------------------
    |
    | Supported drivers: 'signature', 'clamav'
    |
    */
    'antivirus_driver' => env('DOCUMENTS_ANTIVIRUS_DRIVER', 'signature'),
];
