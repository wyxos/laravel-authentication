<?php

namespace Wyxos\LaravelAuthentication\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Rappasoft\LaravelAuthenticationLog\Notifications\NewDevice as RappasoftNewDevice;

class NewDevice extends RappasoftNewDevice
{
    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Your :app account logged in from a new device.', ['app' => config('app.name')]))
            ->view(['html' => 'wyxos-authentication::security-email', 'text' => 'wyxos-authentication::security-email-text'], [
                'appName' => config('app.name'),
                'heading' => __('Hello!'),
                'introduction' => __('Your :app account logged in from a new device.', ['app' => config('app.name')]),
                'details' => [
                    __('Account:') => $notifiable->email ?? $notifiable->name ?? 'Account',
                    __('Time:') => $this->authenticationLog->login_at?->copy()->utc()->format('j F Y \\a\\t H:i \\U\\T\\C') ?? 'Unavailable',
                    __('IP Address:') => $this->authenticationLog->ip_address ?? 'Unavailable',
                    __('Browser:') => $this->authenticationLog->device_name ?: ($this->authenticationLog->user_agent ?? 'Unavailable'),
                ],
                'footer' => __('If this was you, you can ignore this alert. If you suspect any suspicious activity on your account, please change your password.'),
            ]);
    }
}
