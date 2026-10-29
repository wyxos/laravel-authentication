<?php

namespace Wyxos\LaravelAuthentication\Tests;

use Carbon\CarbonImmutable;
use ExampleConsumer\Security\LoginNotificationRecipients;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase;
use ReflectionProperty;
use Wyxos\LaravelAuthentication\AuthenticationServiceProvider;
use Wyxos\LaravelAuthentication\LoginActor;
use Wyxos\LaravelAuthentication\LoginContext;

class InstallationAndEnvironmentTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AuthenticationServiceProvider::class];
    }

    public function test_installer_uses_the_consumer_namespace_and_starts_with_no_recipients(): void
    {
        $this->withConsumer(function (string $root): void {
            $this->artisan('wyxos-authentication:install')->assertSuccessful();
            $path = $root.'/app/Security/LoginNotificationRecipients.php';
            $this->assertFileExists($path);
            $this->assertStringContainsString('namespace ExampleConsumer\\Security;', file_get_contents($path));
            $configuration = require $root.'/config/wyxos-authentication.php';
            $this->assertSame('ExampleConsumer\\Security\\LoginNotificationRecipients', $configuration['login_recipients']);
            require $path;
            $context = new LoginContext(new LoginActor('1', 'User', '1@example.com', 'User 1'), 'web', CarbonImmutable::now('UTC'), '192.0.2.10', 'Chrome on Windows');
            $this->assertSame([], (new LoginNotificationRecipients)->recipients($context));
            $this->assertFalse($configuration['features']['login_notifications']);
        });
    }

    public function test_repeated_install_preserves_user_edited_callback_and_config(): void
    {
        $this->withConsumer(function (string $root): void {
            $this->artisan('wyxos-authentication:install')->assertSuccessful();
            $config = $root.'/config/wyxos-authentication.php';
            $callback = $root.'/app/Security/LoginNotificationRecipients.php';
            file_put_contents($config, '<?php return ["application-owned" => true];');
            file_put_contents($callback, '<?php /* application-owned callback */');
            $this->artisan('wyxos-authentication:install')->assertSuccessful();
            $this->assertSame('<?php return ["application-owned" => true];', file_get_contents($config));
            $this->assertSame('<?php /* application-owned callback */', file_get_contents($callback));
            $this->artisan('wyxos-authentication:install', ['--force' => true])->assertSuccessful();
            $this->assertStringContainsString('LA_LOGIN_NOTIFICATIONS', file_get_contents($config));
            $this->assertStringContainsString('return [];', file_get_contents($callback));
        });
    }

    public function test_la_false_values_override_enabled_legacy_values(): void
    {
        $config = $this->configuration([
            'LA_ENABLED' => 'false', 'WYXOS_AUTH_ENABLED' => 'true',
            'LA_RECORD_LOGINS' => 'false', 'WYXOS_AUTH_RECORD_LOGINS' => 'true',
            'LA_SELF_HISTORY' => 'false', 'WYXOS_AUTH_SELF_HISTORY' => 'true',
            'LA_ADMIN_HISTORY' => 'false', 'WYXOS_AUTH_ADMIN_HISTORY' => 'true',
            'LA_NEW_DEVICE_NOTIFICATIONS' => 'false', 'WYXOS_AUTH_LOGIN_NOTIFICATIONS' => 'true',
            'LA_FAIL_CLOSED' => 'false', 'WYXOS_AUTH_FAIL_CLOSED' => 'true',
            'LA_RETENTION_DAYS' => '45', 'WYXOS_AUTH_RETENTION_DAYS' => '90',
            'LA_PER_PAGE' => '12', 'WYXOS_AUTH_PER_PAGE' => '75',
        ]);
        $this->assertFalse($config['enabled']);
        $this->assertNotContains(true, $config['features']);
        $this->assertFalse($config['fail_closed']);
        $this->assertSame('45', $config['retention_days']);
        $this->assertSame('12', $config['per_page']);
    }

    public function test_legacy_false_settings_are_preserved_without_enabling_recipient_notifications(): void
    {
        $config = $this->configuration([
            'WYXOS_AUTH_ENABLED' => 'false', 'WYXOS_AUTH_RECORD_LOGINS' => 'false',
            'WYXOS_AUTH_SELF_HISTORY' => 'false', 'WYXOS_AUTH_ADMIN_HISTORY' => 'false',
            'WYXOS_AUTH_LOGIN_NOTIFICATIONS' => 'false',
        ]);
        $this->assertFalse($config['enabled']);
        $this->assertNotContains(true, $config['features']);
        $config = $this->configuration(['WYXOS_AUTH_LOGIN_NOTIFICATIONS' => 'true']);
        $this->assertTrue($config['features']['new_device_notifications']);
        $this->assertFalse($config['features']['login_notifications']);
        $config = $this->configuration(['WYXOS_AUTH_LOGIN_NOTIFICATIONS' => 'false', 'LA_LOGIN_NOTIFICATIONS' => 'true']);
        $this->assertFalse($config['features']['new_device_notifications']);
        $this->assertTrue($config['features']['login_notifications']);
    }

    private function configuration(array $values): array
    {
        $keys = [
            'LA_ENABLED', 'LA_RECORD_LOGINS', 'LA_SELF_HISTORY', 'LA_ADMIN_HISTORY', 'LA_NEW_DEVICE_NOTIFICATIONS',
            'LA_LOGIN_NOTIFICATIONS', 'LA_FAIL_CLOSED', 'LA_RETENTION_DAYS', 'LA_PER_PAGE',
            'WYXOS_AUTH_ENABLED', 'WYXOS_AUTH_RECORD_LOGINS', 'WYXOS_AUTH_SELF_HISTORY', 'WYXOS_AUTH_ADMIN_HISTORY',
            'WYXOS_AUTH_LOGIN_NOTIFICATIONS', 'WYXOS_AUTH_FAIL_CLOSED', 'WYXOS_AUTH_RETENTION_DAYS', 'WYXOS_AUTH_PER_PAGE',
        ];
        $original = [];
        foreach ($keys as $key) {
            $original[$key] = ['env' => $_ENV[$key] ?? null, 'server' => $_SERVER[$key] ?? null, 'process' => getenv($key)];
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
            if (array_key_exists($key, $values)) {
                $_ENV[$key] = $_SERVER[$key] = $values[$key];
                putenv($key.'='.$values[$key]);
            }
        }
        try {
            return require __DIR__.'/../config/wyxos-authentication.php';
        } finally {
            foreach ($original as $key => $saved) {
                unset($_ENV[$key], $_SERVER[$key]);
                if ($saved['env'] !== null) {
                    $_ENV[$key] = $saved['env'];
                }
                if ($saved['server'] !== null) {
                    $_SERVER[$key] = $saved['server'];
                }
                putenv($saved['process'] === false ? $key : $key.'='.$saved['process']);
            }
        }
    }

    private function withConsumer(callable $test): void
    {
        $files = new Filesystem;
        $root = sys_get_temp_dir().'/wyxos-auth-install-'.bin2hex(random_bytes(8));
        $files->ensureDirectoryExists($root.'/app');
        $files->ensureDirectoryExists($root.'/config');
        $files->put($root.'/composer.json', json_encode(['autoload' => ['psr-4' => ['ExampleConsumer\\' => 'app/']]], JSON_THROW_ON_ERROR));
        $oldBase = $this->app->basePath();
        $oldApp = $this->app->path();
        $oldConfig = $this->app->configPath();
        $property = new ReflectionProperty(Application::class, 'namespace');
        $oldNamespace = $property->getValue($this->app);
        try {
            $this->app->setBasePath($root);
            $this->app->useAppPath($root.'/app');
            $this->app->useConfigPath($root.'/config');
            $property->setValue($this->app, null);
            $test($root);
        } finally {
            $this->app->setBasePath($oldBase);
            $this->app->useAppPath($oldApp);
            $this->app->useConfigPath($oldConfig);
            $property->setValue($this->app, $oldNamespace);
            if (str_starts_with($root, sys_get_temp_dir().'/wyxos-auth-install-')) {
                $files->deleteDirectory($root);
            }
        }
    }
}
