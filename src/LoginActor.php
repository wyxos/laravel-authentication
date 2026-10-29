<?php

namespace Wyxos\LaravelAuthentication;

final readonly class LoginActor
{
    public function __construct(
        public string $id,
        public string $type,
        public ?string $email,
        public ?string $name,
    ) {}
}
