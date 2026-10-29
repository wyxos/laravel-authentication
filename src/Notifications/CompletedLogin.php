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
            ->line('A user completed a sign-in to '.config('app.name').'.')
            ->line('Account: '.($this->context->actor->email ?? $this->context->actor->name ?? $this->context->actor->id))
            ->line('Guard: '.$this->context->guard)
            ->line('Time: '.$this->context->occurredAt->utc()->format('j F Y \\a\\t H:i \\U\\T\\C'))
            ->line('IP address: '.($this->context->ipAddress ?? 'Unavailable'))
            ->line('Browser / device: '.$this->context->device);
    }
}
