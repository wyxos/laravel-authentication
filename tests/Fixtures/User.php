<?php

namespace Wyxos\LaravelAuthentication\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;

    protected $table = 'package_test_users';

    protected $guarded = [];
}
