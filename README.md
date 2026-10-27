# Laravel authentication

Shared authentication history and new-device email notifications for Laravel 12 and 13, built on [Rappasoft Laravel Authentication Log](https://github.com/rappasoft/laravel-authentication-log).

Use this package after the application has completed authentication. It provides records, new-device notifications and permission-checked history queries. The application owns its login flow, roles, routes and interface.

## Installation

```sh
composer require wyxos/laravel-authentication:^0.1
php artisan vendor:publish --tag=wyxos-authentication-config
php artisan migrate
```

For local development before publication, add a Composer `path` repository pointing at the package checkout. The package's migration runs automatically and creates its own `wyxos_authentication_*` tables. Existing Rappasoft tables and application user tables are unchanged.

## Configuration

Each feature has a separate application setting:

| Environment variable | Default | Behaviour |
| --- | --- | --- |
| `WYXOS_AUTH_RECORD_LOGINS` | `true` | Persist completed sign-ins. |
| `WYXOS_AUTH_SELF_HISTORY` | `true` | Allow a user to query their own records. |
| `WYXOS_AUTH_ADMIN_HISTORY` | `false` | Enable administrator queries, subject to the application's Gate. |
| `WYXOS_AUTH_LOGIN_NOTIFICATIONS` | `true` | Send email when the device fingerprint is new for the account and guard. |

History queries require recording as well as the relevant history setting. A disabled query returns HTTP 404. Hiding history does not stop collection; turn off recording to stop creating history rows.

Notifications work with recording disabled. This retains an identity row with an account reference, email and optional name, plus known-device fingerprints and last-seen dates. Full login events, IP addresses and user-agent strings are not retained in that mode. If both recording and notifications are disabled, `record()` does no database work. Changing switches does not erase existing data.

Use the published config to set `retention_days` (60 by default), `per_page` (25, capped at 100), `fail_closed` and the notification class. Config changes take effect after Laravel's configuration cache is refreshed.

## Record a completed sign-in

```php
use Wyxos\LaravelAuthentication\AuthenticationMonitor;

// Run once after password, two-factor, passkey or social authentication completes.
$result = app(AuthenticationMonitor::class)->record(
    $request,
    $request->user(),
    'web',
);
```

Ordinary Eloquent users need no package traits or new columns. This package does not subscribe to Laravel's authentication events. Do not add a second call from an event listener or use Rappasoft's `AuthenticationLoggable` trait on the same application user, or the two integrations may both process a login. Do not call `record()` during ordinary authenticated requests or session restoration.

The actor must have a stable authentication identifier. For an environment-configured account whose real identifier includes a password hash, pass a separate safe actor to this package:

```php
use Illuminate\Auth\GenericUser;

$email = strtolower($administrator->email);
$actor = new GenericUser([
    'id' => hash('sha256', $email),
    'email' => $email,
    'name' => 'Administrator',
]);

app(AuthenticationMonitor::class)->record($request, $actor, 'admin');
```

Use the same actor class, identifier and guard when reading history. Guard names separate identities. No passwords, session identifiers or access tokens are saved by the package.

## Notifications

The first completed sign-in from a new fingerprint sends Rappasoft's `NewDevice` notification. Further sign-ins from the same fingerprint remain in history without another email. Sending uses Laravel's immediate notification channel so delivery exceptions can be isolated from authentication, including when the application normally uses queued notifications.

The fingerprint is Rappasoft's hash of normalized user-agent information, IP address, accepted language and encoding. Browser version changes are ignored. A network or language change can count as a new device; this is a fingerprint, not proof of physical device identity. Device labels are derived from the browser's user agent.

The package uses the address supplied by Laravel's request. Configure trusted proxies in the application; do not trust arbitrary forwarded-IP headers. No geolocation API, webhooks or external device lookup is enabled.

The default notification class can be replaced with a class accepting a Rappasoft `AuthenticationLog` instance. It receives the internal account's email and name. Notification statuses are `sent`, `failed`, `familiar`, `disabled`, `unavailable` or `unconfirmed`. **Sent means the mail transport accepted the message; it does not confirm inbox delivery.** A failed send does not undo the login, and the device remains known. There is no automatic mail retry or rate-limit service in this integration.

Audit storage failures throw by default. The application must catch that exception, end the newly authenticated session and return a useful error if it requires audited sign-ins. Set `fail_closed` to `false` to return `null` instead. Notification exceptions are always contained, and transport exception messages are not logged by this package.

## Query history

```php
$monitor = app(AuthenticationMonitor::class);
$history = $monitor->selfHistory($request->user(), 'web', page: 1);
```

Responses contain `entries`, `pagination` and `features`. Each entry contains its ID, account, UTC time, IP address, readable device, notification status and new-device flag. Pagination contains `current_page`, `last_page`, `per_page` and `total`. Related identities are eager-loaded so the list does not issue one query per row.

Administrator history requires an explicit application Gate:

```php
Gate::define('viewAuthenticationHistory', fn ($user) => $user->is_super_admin);

$history = $monitor->adminHistory(
    $request->user(),
    'web',
    page: 1,
    account: 'person@example.com', // Optional exact email filter.
);
```

The package never creates or assumes a super-admin role. The application decides who may view other users' records and protects its routes. Use private, no-store responses and exclude these pages from search indexing.

## Retention

Run housekeeping from the application's scheduler:

```php
Schedule::command('wyxos-authentication:prune')->daily();
```

This removes login events older than the retention window, inactive known-device records and unused old identities. A returning device can produce a new-device email after its remembered fingerprint expires. The package does not install a scheduler or worker for the application.

## Development

```sh
composer install
composer test
composer lint
composer source:check
```

Tests use isolated in-memory SQLite and fake notifications. They cover switches, account and guard isolation, administrator authorization, notification failures, retention, pagination and bounded query counts. Notification HTML is rendered separately. A consuming application still needs to verify its actual login hooks, interface, proxy configuration and mail delivery.
