<?php

namespace Wyxos\LaravelAuthentication\Models;

use Illuminate\Database\Eloquent\Model;

class AuthenticationDevice extends Model
{
    protected $table = 'wyxos_authentication_devices';

    protected $guarded = [];

    protected $casts = ['last_seen_at' => 'datetime'];
}
