<?php

declare(strict_types=1);

return [
    'sms_driver' => env('NOTIFICATIONS_SMS_DRIVER', 'sync'), // 'sync' or 'http'
    'sms' => [
        'endpoint' => env('NOTIFICATIONS_SMS_ENDPOINT', 'https://api.sms-gateway.test/v1/messages'),
        'api_key' => env('NOTIFICATIONS_SMS_API_KEY', ''),
        'sender_id' => env('NOTIFICATIONS_SMS_SENDER_ID', 'AFYANOVA'),
        'timeout' => (int) env('NOTIFICATIONS_SMS_TIMEOUT', 15),
        'default_country_code' => env('NOTIFICATIONS_SMS_DEFAULT_COUNTRY_CODE', '+254'),
    ],

    'email_driver' => env('NOTIFICATIONS_EMAIL_DRIVER', 'sync'), // 'sync' or 'mail'
    'email' => [
        'mailer' => env('NOTIFICATIONS_MAILER', null),
        'from_address' => env('NOTIFICATIONS_FROM_ADDRESS', 'noreply@afyanova.org'),
        'from_name' => env('NOTIFICATIONS_FROM_NAME', 'AfyaNova Health'),
    ],
];
