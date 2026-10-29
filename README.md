# Laravel authentication

Shared authentication history, new-device alerts and selected-recipient login notifications for Laravel 12 and 13. The package uses [Rappasoft Laravel Authentication Log](https://github.com/rappasoft/laravel-authentication-log) for its log model, device fingerprint and new-device notification.

The application owns authentication, roles, routes, interface and notification eligibility. Call the package once after a complete sign-in succeeds.

## Installation

```sh
composer require wyxos/laravel-authentication:^0.2
php artisan wyxos-authentication:install
php artisan migrate
```

Before publication, use a Composer `path` repository pointing at the local package checkout. The package creates its own `wyxos_authentication_*` tables and leaves existing user and Rappasoft tables unchanged.

The installer creates `config/wyxos-authentication.php` and an editable `app/Security/LoginNotificationRecipients.php`. It follows the application's PHP namespace. Its default resolver returns an empty array and makes no role or super-admin assumptions. Repeat runs preserve existing files. `--force` explicitly replaces both files; keep application edits before using it. The installer does not run migrations or turn on selected-recipient notifications.

## Feature switches

| Environment variable | Default | Behaviour |
| --- | --- | --- |
| `LA_ENABLED` | `true` | Master switch. Disabled means no recording, notifications or history access. |
| `LA_RECORD_LOGINS` | `true` | Persist completed sign-ins. |
| `LA_SELF_HISTORY` | `true` | Allow a user to query their own recorded sign-ins. |
| `LA_ADMIN_HISTORY` | `false` | Enable other-user history, subject to an explicit application Gate. |
| `LA_NEW_DEVICE_NOTIFICATIONS` | `true` | Notify the signing-in account when its device fingerprint is new. |
| `LA_LOGIN_NOTIFICATIONS` | `false` | Notify application-selected recipients on every completed sign-in. |
| `LA_FAIL_CLOSED` | `true` | Throw when required audit storage fails. The host ends the newly authenticated session. |
| `LA_RETENTION_DAYS` | `60` | Retention window used by the pruning command. |
| `LA_PER_PAGE` | `25` | History page size, capped at 100. |

History access requires recording and the relevant access switch. A disabled query returns HTTP 404. Hiding history does not stop collection; disable recording to stop creating event rows. Switching features off does not erase existing data. Refresh Laravel's config cache after editing environment settings.

The two notification features are independent. Selected-recipient notices can run with history and new-device tracking off and perform no authentication-table queries or writes. New-device alerts without history retain a minimal account identity, device fingerprints and last-seen dates so familiar devices can be recognized. They do not retain full event rows, IP addresses or user-agent strings in that mode.

### Upgrade from 0.1

Keep the package name and `wyxos-authentication` config key. Rename the old **config feature** `features.login_notifications` to `features.new_device_notifications`; the `login_notifications` config feature now enables the new selected-recipient notices. Review existing published config manually because installation preserves it.

`LA_*` environment values take precedence over deprecated `WYXOS_AUTH_*` values, including explicit `false`. `WYXOS_AUTH_LOGIN_NOTIFICATIONS` remains a fallback for **new-device** alerts only. It never enables selected-recipient notices. Other old settings fall back to their matching new names. Use `LA_LOGIN_NOTIFICATIONS=true` explicitly after reviewing the recipient resolver.

Eloquent identities in 0.2 include the model's effective connection name and table as well as its class, guard and identifier. This prevents same-ID users in different tenant connections or tables from sharing personal history or known devices. Existing 0.1 Eloquent identities are retained but are not automatically reassigned because their original tenant source was not recorded. Their personal history starts under the new identity; the first device may be treated as new. Only migrate old rows with a verified source mapping. The GenericUser identity format is unchanged, preserving existing Wyxos history.

## Record a completed sign-in

```php
use Wyxos\LaravelAuthentication\AuthenticationMonitor;

$result = app(AuthenticationMonitor::class)->record(
    $request,
    $request->user(),
    'web',
);
```

Run this once after password, two-factor, passkey or social authentication completes. Do not call it for failed attempts, ordinary authenticated requests or remembered-session restoration. No automatic login listener is registered. Do not combine the hook with Rappasoft's `AuthenticationLoggable` trait on the same application user or a second listener that records the same login.

Ordinary Eloquent users need no package traits or new columns. The actor must have a stable, safe identifier. For an environment-configured account whose real identifier includes a password hash, pass a separate actor containing only safe data:

```php
use Illuminate\Auth\GenericUser;

$email = strtolower($administrator->email);
$actor = new GenericUser([
    'id' => hash('sha256', $email),
    'email' => $email,
    'name' => 'Administrator',
]);

$result = app(AuthenticationMonitor::class)->record($request, $actor, 'admin');
```

Use the same actor class, identifier and guard to query personal history. Guards separate identities. Passwords, tokens, session identifiers and request bodies are not copied into records or callback context.

For Eloquent actors, keep the connection name and table stable too. If the application reuses one connection name and table across dynamically switched tenant databases, provide a tenant-aware safe actor identifier or distinct named connections; connection names must identify the intended tenant boundary.

## Select login-notification recipients

Edit the generated application class. Its `recipients(LoginContext $context): array` method returns eligible application users or Laravel Notifiables. Keep role checks, tenant boundaries, preferences and recipient lookups in the application. Eager-load or query eligible recipients in a bounded operation.

```php
namespace App\Security;

use App\Models\User;
use Wyxos\LaravelAuthentication\LoginContext;

class LoginNotificationRecipients
{
    public function recipients(LoginContext $context): array
    {
        // Define this scope in the app to enforce its permissions and tenant rules.
        return User::eligibleForLoginNotices($context->actor->id, $context->guard)->get()->all();
    }
}
```

An application without a user directory can return an explicitly authorized on-demand recipient, such as `(new AnonymousNotifiable)->route('mail', $approvedAddress)`. The package does not guess recipients or roles. Set `LA_LOGIN_NOTIFICATIONS=true` only after reviewing this class.

`LoginContext` is an immutable object with:

- `actor`: a safe `LoginActor` containing `id`, `type`, optional `email` and optional `name`.
- `guard`: the completed login's guard.
- `occurredAt`: an immutable UTC date/time.
- `ipAddress`: the address supplied by Laravel's trusted-proxy handling.
- `device`: a readable browser/device label.

The context contains no original user object, password, session, access token, raw user agent or request. The application may use the safe identifier to perform its own authorized user or tenant lookup.

`login_recipients` points at the generated class by default. A callable receiving `LoginContext` is also supported. `login_notification` selects a notification class whose constructor accepts `LoginContext`; the default is `Wyxos\LaravelAuthentication\Notifications\CompletedLogin`. Override it for application branding and channels. Recipient routing follows the Notifiable and notification's `via()` method.

Repeated references to the same recipient are deduplicated within one event. Mail-only notifications also deduplicate normalized mail destinations across user models and on-demand wrappers. A recipient lacking a usable mail route is skipped. A resolver failure is contained; one recipient's routing or transport failure does not prevent attempts for the remaining recipients. Exception messages are not included in summaries or logged by the package.

### Delivery result

The `record()` result keeps the signing-in user's new-device `notification` status separate from `recipient_notifications`:

```php
[
    'status' => 'completed',
    'sent' => 2,
    'failed' => 1,
    'skipped' => 1,
]
```

Summary statuses are `disabled`, `no_recipients`, `resolver_failed` and `completed`. `completed` means recipient processing ended; check its counts. `sent` means synchronous notification dispatch completed, not confirmed inbox delivery. Recipient summaries are returned for this call and are not stored as extra history columns. The package does not retry failed deliveries automatically.

## New-device alerts

The signing-in account's first new fingerprint sends `Wyxos\LaravelAuthentication\Notifications\NewDevice`, a mail-safe subclass of Rappasoft's notification. Familiar devices remain in history without another new-device email. The `notification` config key overrides this class; it receives a Rappasoft `AuthenticationLog` instance. It is independent of `login_notification`, the selected-recipient template.

The default new-device and selected-recipient emails use literal HTML and plain-text views. Account text and request-derived values cannot inject Markdown links, images or formatting. Custom application notification classes remain app-owned and should escape all untrusted values. New-device subject, introduction, labels and footer retain the upstream translation keys.

If an earlier published config explicitly selects `Rappasoft\LaravelAuthenticationLog\Notifications\NewDevice`, update that entry to the package's new default class. The installer preserves existing config and never overwrites custom application notification classes automatically.

The fingerprint includes normalized user-agent information, IP address, accepted language and encoding. Browser version changes are ignored. A network or language change can count as a new device; it is not proof of physical device identity. The package trusts Laravel's request IP handling. Configure trusted proxies in the host; arbitrary forwarded-IP headers must not be trusted. No geolocation lookup or webhook is enabled.

New-device statuses are `sent`, `failed`, `familiar`, `disabled`, `unavailable` and `unconfirmed`. Sent means the transport accepted the message, not inbox delivery. A failed notification does not invalidate login. The device remains known, and no automatic retry or notification rate-limiter service is installed.

Both notification paths use immediate dispatch so their failures can be contained without requiring a queue worker. Audit storage errors throw by default; the application must end the newly authenticated session if its policy requires audited logins. `LA_FAIL_CLOSED=false` allows the login to continue without a persisted record. Selected-recipient notifications can still run in that case.

## Query history

```php
$monitor = app(AuthenticationMonitor::class);
$history = $monitor->selfHistory($request->user(), 'web', page: 1);
```

The response contains `entries`, `pagination` and `features`. Entries contain their ID, account, UTC time, IP address, readable device, new-device notification status and new-device flag. Pagination contains `current_page`, `last_page`, `per_page` and `total`. Related identities are eager-loaded.

Administrator queries require an explicit application Gate:

```php
Gate::define('viewAuthenticationHistory', fn ($user) => $user->is_super_admin);

$history = $monitor->adminHistory(
    $request->user(),
    'web',
    page: 1,
    account: 'person@example.com', // Optional exact email filter.
);
```

The package does not create or assume a super-admin role. Protect the application's routes, use private/no-store responses and exclude security pages from search indexing.

## Retention

```php
Schedule::command('wyxos-authentication:prune')->daily();
```

The command removes expired login events, inactive known-device rows and unused old identities. A device may generate a new-device alert after its fingerprint expires. The consuming application runs the scheduler.

## Development

```sh
composer install
composer test
composer lint
composer source:check
```

The tests use in-memory SQLite and fake notifications. They cover switches, account and guard isolation, permissions, notification independence, callback context, deduplication, failure containment, retention, bounded queries, namespace-aware installation and environment precedence. The consuming application still needs to verify its completed-login hook, pages, proxy configuration and actual mail delivery.
