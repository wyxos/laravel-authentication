<?php

namespace Wyxos\LaravelAuthentication\Tests;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Rappasoft\LaravelAuthenticationLog\LaravelAuthenticationLogServiceProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Wyxos\LaravelAuthentication\AuthenticationMonitor;
use Wyxos\LaravelAuthentication\AuthenticationServiceProvider;
use Wyxos\LaravelAuthentication\Models\AuthenticationDevice;
use Wyxos\LaravelAuthentication\Models\AuthenticationEntry;
use Wyxos\LaravelAuthentication\Models\AuthenticationIdentity;
use Wyxos\LaravelAuthentication\Notifications\NewDevice;
use Wyxos\LaravelAuthentication\Tests\Fixtures\User;

class AuthenticationMonitorTest extends TestCase
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
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate')->run();
        Notification::fake();
    }

    private function actor(int $id = 1): GenericUser
    {
        return new GenericUser(['id' => $id, 'email' => "$id@example.com", 'name' => "User $id", 'password' => 'must-never-be-stored']);
    }

    private function request(string $browser = 'Chrome/142.0.1'): Request
    {
        return Request::create('/login', 'POST', [], [], [], ['REMOTE_ADDR' => '192.0.2.10', 'HTTP_USER_AGENT' => "Mozilla/5.0 Windows $browser", 'HTTP_ACCEPT_LANGUAGE' => 'en']);
    }

    private function monitor(): AuthenticationMonitor
    {
        return app(AuthenticationMonitor::class);
    }

    public function test_migration_index_names_fit_mysql_identifier_limits(): void
    {
        foreach (['wyxos_authentication_identities', 'wyxos_authentication_devices', 'wyxos_authentication_log'] as $table) {
            foreach (Schema::getIndexes($table) as $index) {
                $this->assertLessThanOrEqual(64, strlen($index['name']), $index['name']);
            }
        }
    }

    public function test_first_login_notifies_and_browser_version_updates_do_not(): void
    {
        $first = $this->monitor()->record($this->request(), $this->actor());
        $second = $this->monitor()->record($this->request('Chrome/143.0.9'), $this->actor());
        $this->assertSame('sent', $first['notification']);
        $this->assertTrue($first['new_device']);
        $this->assertSame('familiar', $second['notification']);
        $this->assertFalse($second['new_device']);
        $this->assertSame(2, AuthenticationEntry::count());
        Notification::assertSentTimes(NewDevice::class, 1);
        $this->assertStringNotContainsString('must-never-be-stored', AuthenticationIdentity::first()->toJson().AuthenticationEntry::first()->toJson());
    }

    public function test_regular_eloquent_users_need_no_trait_or_user_table_changes(): void
    {
        Schema::create('package_test_users', function (Blueprint $table): void {
            $table->id();
            $table->string('email');
            $table->string('name');
            $table->timestamps();
        });
        $user = User::create(['email' => '1@example.com', 'name' => 'User 1']);
        $this->monitor()->record($this->request(), $user);
        $history = $this->monitor()->selfHistory($user);
        $this->assertSame('1@example.com', $history['entries'][0]['account']);
        $this->assertSame(1, $history['pagination']['total']);
        $this->assertSame(['id', 'email', 'name', 'created_at', 'updated_at'], Schema::getColumnListing('package_test_users'));
    }

    public function test_new_browser_notifies_again_using_the_safe_upstream_compatible_notification(): void
    {
        $this->monitor()->record($this->request(), $this->actor());
        $this->monitor()->record($this->request('Firefox/144.0'), $this->actor());
        Notification::assertSentTimes(NewDevice::class, 2);
        $html = (new NewDevice(AuthenticationEntry::first()))->toMail(AuthenticationIdentity::first())->render();
        $this->assertStringContainsString('192.0.2.10', $html);
    }

    public function test_history_is_scoped_to_actor_and_guard(): void
    {
        $this->monitor()->record($this->request(), $this->actor(1));
        $this->monitor()->record($this->request(), $this->actor(2));
        $this->monitor()->record($this->request(), $this->actor(1), 'admin');
        $history = $this->monitor()->selfHistory($this->actor(1));
        $this->assertSame(1, $history['pagination']['total']);
        $this->assertSame('1@example.com', $history['entries'][0]['account']);
        $this->assertSame(0, $this->monitor()->selfHistory($this->actor(3))['pagination']['total']);
    }

    public function test_disabling_every_feature_performs_no_database_writes_or_notifications(): void
    {
        config(['wyxos-authentication.features' => array_fill_keys(array_keys($this->monitor()->features()), false)]);
        $this->assertNull($this->monitor()->record($this->request(), $this->actor()));
        $this->assertSame(0, AuthenticationIdentity::count());
        $this->assertSame(0, AuthenticationDevice::count());
        $this->assertSame(0, AuthenticationEntry::count());
        Notification::assertNothingSent();
    }

    public function test_notifications_work_without_persistent_login_history(): void
    {
        config(['wyxos-authentication.features.record_logins' => false]);
        $this->assertSame('sent', $this->monitor()->record($this->request(), $this->actor())['notification']);
        $this->assertSame('familiar', $this->monitor()->record($this->request(), $this->actor())['notification']);
        $this->assertSame(0, AuthenticationEntry::count());
        $this->assertSame(1, AuthenticationDevice::count());
        Notification::assertSentTimes(NewDevice::class, 1);
        $this->expectException(HttpException::class);
        $this->monitor()->selfHistory($this->actor());
    }

    public function test_notification_switch_does_not_disable_history(): void
    {
        config(['wyxos-authentication.features.new_device_notifications' => false]);
        $this->assertSame('disabled', $this->monitor()->record($this->request(), $this->actor())['notification']);
        $this->assertSame(1, $this->monitor()->selfHistory($this->actor())['pagination']['total']);
        Notification::assertNothingSent();
    }

    public function test_self_history_switch_is_server_enforced(): void
    {
        config(['wyxos-authentication.features.self_history' => false]);
        $this->monitor()->record($this->request(), $this->actor());
        $this->expectException(HttpException::class);
        $this->monitor()->selfHistory($this->actor());
    }

    public function test_admin_history_is_disabled_by_default(): void
    {
        Gate::define('viewAuthenticationHistory', fn () => true);
        $this->expectException(HttpException::class);
        $this->monitor()->adminHistory($this->actor());
    }

    public function test_admin_history_requires_explicit_permission(): void
    {
        config(['wyxos-authentication.features.admin_history' => true]);
        Gate::define('viewAuthenticationHistory', fn () => false);
        $this->expectException(AuthorizationException::class);
        $this->monitor()->adminHistory($this->actor());
    }

    public function test_authorized_admin_history_is_paginated_filtered_and_guard_scoped(): void
    {
        config(['wyxos-authentication.features.admin_history' => true, 'wyxos-authentication.per_page' => 2]);
        Gate::define('viewAuthenticationHistory', fn ($viewer) => $viewer->getAuthIdentifier() === 99);
        foreach ([1, 2, 3] as $id) {
            $this->monitor()->record($this->request(), $this->actor($id));
        }
        $this->monitor()->record($this->request(), $this->actor(1), 'admin');
        $history = $this->monitor()->adminHistory($this->actor(99));
        $this->assertCount(2, $history['entries']);
        $this->assertSame(3, $history['pagination']['total']);
        $this->assertCount(1, $this->monitor()->adminHistory($this->actor(99), 'web', 2)['entries']);
        $this->assertSame(1, $this->monitor()->adminHistory($this->actor(99), 'web', 1, '1@example.com')['pagination']['total']);
    }

    public function test_history_query_count_does_not_grow_per_record(): void
    {
        config(['wyxos-authentication.features.admin_history' => true]);
        Gate::define('viewAuthenticationHistory', fn () => true);
        foreach ([1, 2] as $id) {
            $this->monitor()->record($this->request(), $this->actor($id));
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->monitor()->adminHistory($this->actor(99));
        $small = count(DB::getQueryLog());
        foreach (range(3, 20) as $id) {
            $this->monitor()->record($this->request(), $this->actor($id));
        }
        DB::flushQueryLog();
        $this->monitor()->adminHistory($this->actor(99));
        $this->assertSame($small, count(DB::getQueryLog()));
        $this->assertLessThanOrEqual(3, $small);
    }

    public function test_notification_failure_keeps_successful_login_record(): void
    {
        Notification::shouldReceive('sendNow')->once()->andThrow(new RuntimeException('private transport detail'));
        $result = $this->monitor()->record($this->request(), $this->actor());
        $this->assertSame('failed', $result['notification']);
        $this->assertTrue(AuthenticationEntry::first()->login_successful);
        $this->assertSame('failed', AuthenticationEntry::first()->notification_status);
    }

    public function test_fail_closed_can_be_disabled_for_storage_failures(): void
    {
        Schema::drop('wyxos_authentication_log');
        config(['wyxos-authentication.fail_closed' => false]);
        $this->assertNull($this->monitor()->record($this->request(), $this->actor()));
        Notification::assertNothingSent();
        $this->assertSame(0, AuthenticationIdentity::count());
    }

    public function test_default_storage_failure_bubbles_for_host_fail_closed_handling(): void
    {
        Schema::drop('wyxos_authentication_log');
        $this->expectException(QueryException::class);
        $this->monitor()->record($this->request(), $this->actor());
    }

    public function test_pruning_retains_recent_records_and_expires_old_device_memory(): void
    {
        $this->travel(-61)->days();
        $this->monitor()->record($this->request(), $this->actor(1));
        $this->travelBack();
        $this->monitor()->record($this->request(), $this->actor(2));
        $this->artisan('wyxos-authentication:prune')->assertSuccessful();
        $this->assertSame(1, AuthenticationEntry::count());
        $this->assertSame(1, AuthenticationDevice::count());
        $this->assertSame(1, AuthenticationIdentity::count());
    }

    public function test_same_id_users_from_real_tenant_databases_and_tables_cannot_read_each_others_history(): void
    {
        foreach (['tenant-one', 'tenant-two'] as $connection) {
            config(["database.connections.$connection" => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
            foreach (['package_test_users', 'other_users'] as $table) {
                Schema::connection($connection)->create($table, function (Blueprint $blueprint): void {
                    $blueprint->id();
                    $blueprint->string('email');
                    $blueprint->string('name');
                    $blueprint->timestamps();
                });
            }
        }
        $first = User::on('tenant-one')->create(['email' => '1@example.com', 'name' => 'Tenant one']);
        $second = User::on('tenant-two')->create(['email' => '2@example.com', 'name' => 'Tenant two']);
        $third = (new User(['email' => '3@example.com', 'name' => 'Other table']))->setConnection('tenant-one')->setTable('other_users');
        $third->save();
        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame($first->getKey(), $third->getKey());
        foreach ([$first, $second, $third] as $actor) {
            $this->monitor()->record($this->request(), $actor);
        }
        $this->monitor()->record($this->request(), $first, 'admin');

        foreach ([$first, $second, $third] as $actor) {
            $history = $this->monitor()->selfHistory($actor);
            $this->assertSame(1, $history['pagination']['total']);
            $this->assertSame([$actor->email], array_column($history['entries'], 'account'));
        }
        $admin = $this->monitor()->selfHistory($first, 'admin');
        $this->assertSame(1, $admin['pagination']['total']);
        $this->assertSame(['1@example.com'], array_column($admin['entries'], 'account'));
        $this->assertSame(4, AuthenticationIdentity::count());
    }

    public function test_generic_user_identity_key_remains_compatible_with_previous_wyxos_history(): void
    {
        $this->monitor()->record($this->request(), $this->actor());
        $expected = hash('sha256', json_encode([GenericUser::class, 'web', '1'], JSON_THROW_ON_ERROR));
        $this->assertSame($expected, AuthenticationIdentity::first()->subject_key);
        $this->assertSame(1, $this->monitor()->selfHistory($this->actor())['pagination']['total']);
    }
}
