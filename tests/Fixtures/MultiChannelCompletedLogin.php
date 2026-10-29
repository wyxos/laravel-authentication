<?php

namespace Wyxos\LaravelAuthentication\Tests\Fixtures;

use Wyxos\LaravelAuthentication\Notifications\CompletedLogin;

class MultiChannelCompletedLogin extends CompletedLogin
{
    public function via($notifiable): array
    {
        return ['database', 'mail'];
    }
}
