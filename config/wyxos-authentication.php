<?php

use Wyxos\LaravelAuthentication\Notifications\CompletedLogin;
use Wyxos\LaravelAuthentication\Notifications\NewDevice;

return [
    'enabled' => env('LA_ENABLED', env('WYXOS_AUTH_ENABLED', true)),
    'features' => [
        'record_logins' => env('LA_RECORD_LOGINS', env('WYXOS_AUTH_RECORD_LOGINS', true)),
        'self_history' => env('LA_SELF_HISTORY', env('WYXOS_AUTH_SELF_HISTORY', true)),
        'admin_history' => env('LA_ADMIN_HISTORY', env('WYXOS_AUTH_ADMIN_HISTORY', false)),
        'new_device_notifications' => env('LA_NEW_DEVICE_NOTIFICATIONS', env('WYXOS_AUTH_LOGIN_NOTIFICATIONS', true)),
        'login_notifications' => env('LA_LOGIN_NOTIFICATIONS', false),
    ],
    // The host calls record only after its complete authentication flow succeeds.
    'fail_closed' => env('LA_FAIL_CLOSED', env('WYXOS_AUTH_FAIL_CLOSED', true)),
    'notification' => NewDevice::class,
    'login_recipients' => app()->getNamespace().'Security\\LoginNotificationRecipients',
    'login_notification' => CompletedLogin::class,
    'retention_days' => env('LA_RETENTION_DAYS', env('WYXOS_AUTH_RETENTION_DAYS', 60)),
    'per_page' => env('LA_PER_PAGE', env('WYXOS_AUTH_PER_PAGE', 25)),
];
