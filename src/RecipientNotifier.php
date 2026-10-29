<?php

namespace Wyxos\LaravelAuthentication;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Throwable;

class RecipientNotifier
{
    public function send(LoginContext $context, bool $enabled): array
    {
        $summary = ['status' => $enabled ? 'no_recipients' : 'disabled', 'sent' => 0, 'failed' => 0, 'skipped' => 0];
        if (! $enabled) {
            return $summary;
        }
        try {
            $resolver = config('wyxos-authentication.login_recipients');
            $recipients = is_callable($resolver) ? $resolver($context) : app($resolver)->recipients($context);
            if (! is_array($recipients)) {
                throw new \UnexpectedValueException('The recipient resolver must return an array.');
            }
        } catch (Throwable) {
            $summary['status'] = 'resolver_failed';

            return $summary;
        }
        $seen = [];
        foreach ($recipients as $recipient) {
            if (! is_object($recipient) || ! method_exists($recipient, 'routeNotificationFor')) {
                $summary['skipped']++;

                continue;
            }
            try {
                $class = config('wyxos-authentication.login_notification');
                $notification = new $class($context);
                $channels = array_values(array_filter($notification->via($recipient), fn ($channel): bool => $channel !== 'mail' || (bool) $recipient->routeNotificationFor('mail', $notification)));
                if ($channels === []) {
                    $summary['skipped']++;

                    continue;
                }
                $key = $this->key($recipient, $channels, $notification);
                if (isset($seen[$key])) {
                    $summary['skipped']++;

                    continue;
                }
                $seen[$key] = true;
                Notification::sendNow($recipient, $notification, $channels);
                $summary['sent']++;
            } catch (Throwable) {
                // Resolver, recipient and transport exceptions can contain secrets. Keep counts only.
                $summary['failed']++;
            }
        }
        if ($recipients !== []) {
            $summary['status'] = 'completed';
        }

        return $summary;
    }

    private function key(object $recipient, array $channels, object $notification): string
    {
        if ($channels === ['mail']) {
            $mail = $recipient->routeNotificationFor('mail', $notification);
            $addresses = is_array($mail) ? (array_is_list($mail) ? $mail : array_keys($mail)) : [$mail];
            $addresses = array_map(fn ($address): string => strtolower(trim((string) $address)), $addresses);
            sort($addresses);

            return hash('sha256', json_encode(['mail', $addresses], JSON_THROW_ON_ERROR));
        }
        if ($recipient instanceof AnonymousNotifiable) {
            $routes = $recipient->routes;
            if (isset($routes['mail'])) {
                $mail = $routes['mail'];
                $addresses = is_array($mail) ? (array_is_list($mail) ? $mail : array_keys($mail)) : [$mail];
                $addresses = array_map(fn ($address): string => strtolower(trim((string) $address)), $addresses);
                sort($addresses);
                $routes['mail'] = $addresses;
            }
            ksort($routes);

            return hash('sha256', json_encode([get_class($recipient), $routes], JSON_THROW_ON_ERROR));
        }
        if ($recipient instanceof Model && $recipient->getKey() !== null) {
            return hash('sha256', json_encode([get_class($recipient), $recipient->getConnectionName(), $recipient->getTable(), $recipient->getKey()], JSON_THROW_ON_ERROR));
        }
        if ($recipient instanceof Authenticatable && $recipient->getAuthIdentifier() !== null) {
            return hash('sha256', json_encode([get_class($recipient), $recipient->getAuthIdentifier()], JSON_THROW_ON_ERROR));
        }

        return 'object:'.spl_object_id($recipient);
    }
}
