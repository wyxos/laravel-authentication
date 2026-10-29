<?php

namespace Wyxos\LaravelAuthentication\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Rappasoft\LaravelAuthenticationLog\LaravelAuthenticationLogServiceProvider;
use Rappasoft\LaravelAuthenticationLog\Notifications\NewDevice;
use RuntimeException;
use Wyxos\LaravelAuthentication\AuthenticationMonitor;
use Wyxos\LaravelAuthentication\AuthenticationServiceProvider;
use Wyxos\LaravelAuthentication\LoginActor;
use Wyxos\LaravelAuthentication\LoginContext;
use Wyxos\LaravelAuthentication\Models\AuthenticationDevice;
use Wyxos\LaravelAuthentication\Models\AuthenticationEntry;
use Wyxos\LaravelAuthentication\Models\AuthenticationIdentity;
use Wyxos\LaravelAuthentication\Notifications\CompletedLogin;
use Wyxos\LaravelAuthentication\Tests\Fixtures\MultiChannelCompletedLogin;
use Wyxos\LaravelAuthentication\Tests\Fixtures\User;

class RecipientNotificationsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaravelAuthenticationLogServiceProvider::class, AuthenticationServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('mail.default', 'array');
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('wyxos-authentication.enabled', true);
        $app['config']->set('wyxos-authentication.features', [
            'record_logins' => false, 'self_history' => false, 'admin_history' => false,
            'new_device_notifications' => false, 'login_notifications' => true,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate')->run();
        Notification::fake();
    }

    private function recipient(string $email = '2@example.com'): AnonymousNotifiable
    {
        return (new AnonymousNotifiable)->route('mail', $email);
    }

    private function record(): ?array
    {
        return app(AuthenticationMonitor::class)->record(
            Request::create('/login', 'POST', [], [], [], ['REMOTE_ADDR' => '192.0.2.10', 'HTTP_USER_AGENT' => 'Chrome/142.0 Windows']),
            new GenericUser(['id' => 1, 'email' => '1@example.com', 'name' => 'User 1', 'password' => 'private-password', 'remember_token' => 'private-token']),
            'admin',
        );
    }

    public function test_callback_receives_safe_completed_login_context(): void
    {
        $received = null;
        config(['wyxos-authentication.login_recipients' => function (LoginContext $context) use (&$received): array {
            $received = $context;

            return [$this->recipient()];
        }]);
        $result = $this->record();
        $this->assertSame('1', $received->actor->id);
        $this->assertSame('1@example.com', $received->actor->email);
        $this->assertSame('admin', $received->guard);
        $this->assertSame('UTC', $received->occurredAt->timezoneName);
        $this->assertSame('192.0.2.10', $received->ipAddress);
        $this->assertSame('Chrome on Windows', $received->device);
        $this->assertStringNotContainsString('private-', json_encode($received));
        $this->assertFalse(method_exists($received->actor, 'getAuthPassword'));
        $this->assertSame(['status' => 'completed', 'sent' => 1, 'failed' => 0, 'skipped' => 0], $result['recipient_notifications']);
        $this->assertSame('disabled', $result['notification']);
        Notification::assertSentTimes(CompletedLogin::class, 1);
    }

    public function test_recipient_only_mode_does_not_query_or_write_authentication_tables(): void
    {
        config(['wyxos-authentication.login_recipients' => fn () => [$this->recipient()]]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->record();
        $this->assertCount(0, DB::getQueryLog());
        $this->assertSame(0, AuthenticationIdentity::count());
        $this->assertSame(0, AuthenticationDevice::count());
        $this->assertSame(0, AuthenticationEntry::count());
    }

    public function test_no_recipients_and_disabled_switch_never_send(): void
    {
        config(['wyxos-authentication.login_recipients' => fn () => []]);
        $this->assertSame('no_recipients', $this->record()['recipient_notifications']['status']);
        config(['wyxos-authentication.features.login_notifications' => false,
            'wyxos-authentication.login_recipients' => fn () => throw new RuntimeException('must not resolve')]);
        $this->assertNull($this->record());
        Notification::assertNothingSent();
    }

    public function test_disabled_package_masks_all_features_and_never_resolves(): void
    {
        config(['wyxos-authentication.enabled' => false,
            'wyxos-authentication.login_recipients' => fn () => throw new RuntimeException('must not resolve')]);
        $this->assertNotContains(true, app(AuthenticationMonitor::class)->features());
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertNull($this->record());
        $this->assertCount(0, DB::getQueryLog());
        Notification::assertNothingSent();
    }

    public function test_every_completed_login_notifies_recipients_even_for_a_familiar_device(): void
    {
        config(['wyxos-authentication.features.record_logins' => true,
            'wyxos-authentication.features.new_device_notifications' => true,
            'wyxos-authentication.login_recipients' => fn () => [$this->recipient()]]);
        $first = $this->record();
        $second = $this->record();
        $this->assertSame('sent', $first['notification']);
        $this->assertSame('familiar', $second['notification']);
        $this->assertSame(1, $second['recipient_notifications']['sent']);
        Notification::assertSentTimes(NewDevice::class, 1);
        Notification::assertSentTimes(CompletedLogin::class, 2);
    }

    public function test_duplicate_eligible_users_and_anonymous_mail_wrappers_send_once_each(): void
    {
        $user = new AuthenticationIdentity(['id' => 9, 'email' => '3@example.com']);
        config(['wyxos-authentication.login_recipients' => fn () => [
            $this->recipient(), $this->recipient('2@EXAMPLE.COM'), (new AnonymousNotifiable)->route('mail', ['2@example.com' => 'Owner']),
            $user, clone $user, 'not a Notifiable', new AnonymousNotifiable,
        ]]);
        $summary = $this->record()['recipient_notifications'];
        $this->assertSame(2, $summary['sent']);
        $this->assertSame(5, $summary['skipped']);
        Notification::assertSentTimes(CompletedLogin::class, 2);
    }

    public function test_resolver_failure_is_contained_without_leaking_exception_details(): void
    {
        config(['wyxos-authentication.login_recipients' => fn () => throw new RuntimeException('private-token')]);
        $result = $this->record();
        $this->assertSame('resolver_failed', $result['recipient_notifications']['status']);
        $this->assertStringNotContainsString('private-token', json_encode($result));
        Notification::assertNothingSent();
    }

    public function test_mail_recipient_deduplication_crosses_model_and_anonymous_wrappers(): void
    {
        config(['wyxos-authentication.login_recipients' => fn () => [
            new AuthenticationIdentity(['id' => 9, 'email' => '2@example.com']),
            $this->recipient('2@EXAMPLE.COM'),
            $this->recipient()->route('slack', 'https://example.com/unused-route'),
        ]]);
        $summary = $this->record()['recipient_notifications'];
        $this->assertSame(1, $summary['sent']);
        $this->assertSame(2, $summary['skipped']);
        Notification::assertSentTimes(CompletedLogin::class, 1);
    }

    public function test_one_recipient_failure_does_not_prevent_remaining_deliveries(): void
    {
        $sent = [];
        Notification::shouldReceive('sendNow')->twice()->andReturnUsing(function ($recipient) use (&$sent): void {
            $email = $recipient->routes['mail'];
            if ($email === '2@example.com') {
                throw new RuntimeException('private-transport-token');
            }
            $sent[] = $email;
        });
        config(['wyxos-authentication.login_recipients' => fn () => [$this->recipient(), $this->recipient('3@example.com')]]);
        $result = $this->record();
        $this->assertSame(['3@example.com'], $sent);
        $this->assertSame(['status' => 'completed', 'sent' => 1, 'failed' => 1, 'skipped' => 0], $result['recipient_notifications']);
        $this->assertStringNotContainsString('private-', json_encode($result));
    }

    public function test_generic_recipient_notification_renders_readable_safe_context(): void
    {
        $context = LoginContext::from(Request::create('/login', 'POST', [], [], [], ['REMOTE_ADDR' => '192.0.2.10']), new GenericUser(['id' => 1, 'email' => '1@example.com']), 'web');
        $html = (new CompletedLogin($context))->toMail($this->recipient())->render();
        $this->assertStringContainsString('1@example.com', $html);
        $this->assertStringContainsString('UTC', $html);
        $this->assertStringContainsString('192.0.2.10', $html);
    }

    public function test_generic_notification_normalizes_a_direct_context_before_labelling_it_utc(): void
    {
        $context = new LoginContext(new LoginActor('1', 'User', '1@example.com', 'User 1'), 'web',
            CarbonImmutable::parse('2026-10-29T16:30:00+04:00'), '192.0.2.10', 'Chrome on Windows');
        $html = (new CompletedLogin($context))->toMail($this->recipient())->render();
        $this->assertStringContainsString('29 October 2026 at 12:30 UTC', $html);
        $this->assertStringNotContainsString('16:30 UTC', $html);
    }

    public function test_multichannel_recipients_keep_same_ids_from_different_tenant_connections_distinct(): void
    {
        $first = (new User(['id' => 7, 'email' => '2@example.com']))->setConnection('tenant-one');
        $second = (new User(['id' => 7, 'email' => '3@example.com']))->setConnection('tenant-two');
        config(['wyxos-authentication.login_notification' => MultiChannelCompletedLogin::class,
            'wyxos-authentication.login_recipients' => fn () => [$first, $second, clone $first]]);
        $summary = $this->record()['recipient_notifications'];
        $this->assertSame(2, $summary['sent']);
        $this->assertSame(1, $summary['skipped']);
        Notification::assertSentTimes(MultiChannelCompletedLogin::class, 2);
    }

    public function test_selected_recipients_still_run_after_a_fail_open_history_error(): void
    {
        config(['wyxos-authentication.fail_closed' => false,
            'wyxos-authentication.features.record_logins' => true,
            'wyxos-authentication.features.new_device_notifications' => true,
            'wyxos-authentication.login_recipients' => fn () => [$this->recipient()]]);
        Schema::drop('wyxos_authentication_log');
        $result = $this->record();
        $this->assertSame('', $result['id']);
        $this->assertSame('unavailable', $result['notification']);
        $this->assertSame(1, $result['recipient_notifications']['sent']);
        $this->assertSame(0, AuthenticationIdentity::count());
        Notification::assertSentTimes(CompletedLogin::class, 1);
        Notification::assertNotSentTo(new AuthenticationIdentity(['email' => '1@example.com']), NewDevice::class);
    }
}
