<?php

namespace Wyxos\LaravelAuthentication\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Notifications\Notifiable;

class AuthenticationIdentity extends Model
{
    use Notifiable;

    protected $table = 'wyxos_authentication_identities';

    protected $guarded = [];

    public function authentications(): MorphMany
    {
        return $this->morphMany(AuthenticationEntry::class, 'authenticatable');
    }

    public function notifyAuthenticationLogVia(): array
    {
        return ['mail'];
    }
}
