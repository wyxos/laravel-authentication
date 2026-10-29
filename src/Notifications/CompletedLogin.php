<?php

namespace Wyxos\LaravelAuthentication\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Wyxos\LaravelAuthentication\LoginContext;

class CompletedLogin extends Notification
{
    public function __construct(public readonly LoginContext $context) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('A user signed in to '.config('app.name'))
            ->view(['html' => 'wyxos-authentication::security-email', 'text' => 'wyxos-authentication::security-email-text'], [
                'appName' => config('app.name'),
                'heading' => 'A user signed in',
                'introduction' => 'A user completed a sign-in to '.config('app.name').'.',
                'details' => [
                    'Account' => $this->context->actor->email ?? $this->context->actor->name ?? $this->context->actor->id,
                    'Guard' => $this->context->guard,
                    'Time' => $this->context->occurredAt->utc()->format('j F Y \\a\\t H:i \\U\\T\\C'),
                    'IP address' => $this->context->ipAddress ?? 'Unavailable',
                    'Browser / device' => $this->context->device,
                ],
                'footer' => 'You received this security notification as an eligible login-alert recipient.',
            ]);
    }
}
