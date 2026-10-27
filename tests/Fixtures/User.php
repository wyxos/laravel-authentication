<?php

namespace Wyxos\LaravelAuthentication\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    protected $table = 'package_test_users';

    protected $guarded = [];
}
