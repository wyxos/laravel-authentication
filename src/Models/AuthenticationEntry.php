<?php

namespace Wyxos\LaravelAuthentication\Models;

use Rappasoft\LaravelAuthenticationLog\Models\AuthenticationLog;

class AuthenticationEntry extends AuthenticationLog
{
    public function getTable(): string
    {
        return 'wyxos_authentication_log';
    }

    public function __construct(array $attributes = [])
    {
        $this->mergeFillable(['notification_status', 'new_device']);
        parent::__construct($attributes);
        $this->mergeCasts(['new_device' => 'boolean']);
        // Identities, devices and events share the application's default connection.
        $this->setConnection(null);
    }
}
