<?php

use Rappasoft\LaravelAuthenticationLog\Notifications\NewDevice;

return [
    'features' => [
        'record_logins' => env('WYXOS_AUTH_RECORD_LOGINS', true),
        'self_history' => env('WYXOS_AUTH_SELF_HISTORY', true),
        'admin_history' => env('WYXOS_AUTH_ADMIN_HISTORY', false),
        'login_notifications' => env('WYXOS_AUTH_LOGIN_NOTIFICATIONS', true),
    ],
    // The host calls record only after its complete authentication flow succeeds.
    'fail_closed' => true,
    'notification' => NewDevice::class,
    'retention_days' => 60,
    'per_page' => 25,
];
