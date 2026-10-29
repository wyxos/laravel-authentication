<?php

namespace Wyxos\LaravelAuthentication;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Rappasoft\LaravelAuthenticationLog\Helpers\DeviceFingerprint;

final readonly class LoginContext
{
    public function __construct(
        public LoginActor $actor,
        public string $guard,
        public CarbonImmutable $occurredAt,
        public ?string $ipAddress,
        public string $device,
    ) {}

    public static function from(Request $request, Authenticatable $actor, string $guard): self
    {
        $id = $actor->getAuthIdentifier();
        if ((! is_string($id) && ! is_int($id)) || (string) $id === '' || strlen($guard) > 64 || $guard === '') {
            throw new InvalidArgumentException('Authentication requires a stable actor identifier and a guard of 1–64 characters.');
        }
        $email = $actor->email ?? null;
        $name = $actor->name ?? null;

        return new self(new LoginActor(
            (string) $id,
            get_class($actor),
            is_string($email) && strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
            is_string($name) ? mb_substr($name, 0, 255) : null,
        ), $guard, CarbonImmutable::now('UTC'), $request->ip(), DeviceFingerprint::generateDeviceName($request));
    }
}
