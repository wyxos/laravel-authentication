<?php

namespace Wyxos\LaravelAuthentication;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Rappasoft\LaravelAuthenticationLog\Helpers\DeviceFingerprint;
use Throwable;
use Wyxos\LaravelAuthentication\Models\AuthenticationDevice;
use Wyxos\LaravelAuthentication\Models\AuthenticationEntry;
use Wyxos\LaravelAuthentication\Models\AuthenticationIdentity;

class AuthenticationMonitor
{
    public function features(): array
    {
        $features = config('wyxos-authentication.features', []);

        return array_map(fn (string $key): bool => (bool) ($features[$key] ?? false), array_combine(
            ['record_logins', 'self_history', 'admin_history', 'login_notifications'],
            ['record_logins', 'self_history', 'admin_history', 'login_notifications'],
        ));
    }

    public function record(Request $request, Authenticatable $actor, string $guard = 'web'): ?array
    {
        $features = $this->features();
        if (! $features['record_logins'] && ! $features['login_notifications']) {
            return null;
        }
        try {
            [$identity, $entry] = DB::transaction(function () use ($request, $actor, $guard, $features): array {
                $identity = AuthenticationIdentity::firstOrCreate(['subject_key' => $this->subjectKey($actor, $guard)], [
                    'guard' => $guard, 'email' => $this->email($actor), 'name' => $this->name($actor),
                ]);
                $identity->forceFill(['email' => $this->email($actor), 'name' => $this->name($actor)])->save();
                $fingerprint = DeviceFingerprint::generate($request);
                $device = AuthenticationDevice::firstOrCreate([
                    'identity_id' => $identity->getKey(), 'fingerprint' => $fingerprint,
                ], ['last_seen_at' => now()]);
                $isNew = $device->wasRecentlyCreated;
                $device->forceFill(['last_seen_at' => now()])->save();
                $entry = new AuthenticationEntry([
                    'ip_address' => $request->ip(),
                    'user_agent' => mb_substr((string) $request->userAgent(), 0, 1024),
                    'device_id' => $fingerprint,
                    'device_name' => DeviceFingerprint::generateDeviceName($request),
                    'login_at' => now(), 'last_activity_at' => now(), 'login_successful' => true,
                    'location' => null, 'new_device' => $isNew,
                    'notification_status' => ! $features['login_notifications'] ? 'disabled' : ($isNew ? 'unconfirmed' : 'familiar'),
                ]);
                $entry->authenticatable()->associate($identity);
                if ($features['record_logins']) {
                    $entry->save();
                }

                return [$identity, $entry];
            });
        } catch (Throwable $exception) {
            if (config('wyxos-authentication.fail_closed', true)) {
                throw $exception;
            }

            return null;
        }
        if ($features['login_notifications'] && $entry->new_device) {
            $status = 'unavailable';
            if ($identity->email) {
                try {
                    $class = config('wyxos-authentication.notification');
                    Notification::sendNow($identity, new $class($entry), ['mail']);
                    $status = 'sent';
                } catch (Throwable) {
                    // Transport errors can contain credentials. Never expose or log their messages.
                    $status = 'failed';
                }
            }
            $entry->notification_status = $status;
            if ($entry->exists) {
                try {
                    $entry->save();
                } catch (Throwable) {
                    // Delivery/status failure must not invalidate an already audited login.
                }
            }
        }

        return $this->entry($entry, $identity);
    }

    public function selfHistory(Authenticatable $actor, string $guard = 'web', int $page = 1): array
    {
        $features = $this->features();
        abort_unless($features['record_logins'] && $features['self_history'], 404);
        $identity = AuthenticationIdentity::where('subject_key', $this->subjectKey($actor, $guard))->first();

        return $this->history(AuthenticationEntry::query()->where('authenticatable_type', (new AuthenticationIdentity)->getMorphClass())
            ->where('authenticatable_id', $identity?->getKey() ?? 0), $page);
    }

    public function adminHistory(Authenticatable $viewer, string $guard = 'web', int $page = 1, ?string $account = null): array
    {
        $features = $this->features();
        abort_unless($features['record_logins'] && $features['admin_history'], 404);
        Gate::forUser($viewer)->authorize('viewAuthenticationHistory');
        $query = AuthenticationEntry::query()->whereHasMorph('authenticatable', [AuthenticationIdentity::class], function (Builder $query) use ($guard, $account): void {
            $query->where('guard', $guard);
            if ($account !== null && $account !== '') {
                $query->where('email', $account);
            }
        });

        return $this->history($query, $page);
    }

    private function history(Builder $query, int $page): array
    {
        $perPage = max(1, min(100, (int) config('wyxos-authentication.per_page', 25)));
        $rows = $query->with('authenticatable')->orderByDesc('login_at')->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', max(1, $page));

        return [
            'entries' => $rows->getCollection()->map(fn (AuthenticationEntry $entry): array => $this->entry($entry, $entry->authenticatable))->all(),
            'pagination' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total()],
            'features' => $this->features(),
        ];
    }

    private function entry(AuthenticationEntry $entry, AuthenticationIdentity $identity): array
    {
        return [
            'id' => (string) ($entry->getKey() ?? ''), 'account' => $identity->email ?? $identity->name ?? 'Account',
            'occurred_at' => $entry->login_at?->utc()->toIso8601String(), 'ip_address' => $entry->ip_address,
            'device' => $entry->device_name, 'notification' => $entry->notification_status, 'new_device' => (bool) $entry->new_device,
        ];
    }

    private function subjectKey(Authenticatable $actor, string $guard): string
    {
        $id = $actor->getAuthIdentifier();
        if ((! is_string($id) && ! is_int($id)) || (string) $id === '' || strlen($guard) > 64 || $guard === '') {
            throw new InvalidArgumentException('Authentication requires a stable actor identifier and a guard of 1–64 characters.');
        }

        return hash('sha256', json_encode([get_class($actor), $guard, (string) $id], JSON_THROW_ON_ERROR));
    }

    private function email(Authenticatable $actor): ?string
    {
        $email = $actor->email ?? null;

        return is_string($email) && strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    private function name(Authenticatable $actor): ?string
    {
        $name = $actor->name ?? null;

        return is_string($name) ? mb_substr($name, 0, 255) : null;
    }
}
