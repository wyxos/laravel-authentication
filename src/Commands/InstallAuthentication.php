<?php

namespace Wyxos\LaravelAuthentication\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class InstallAuthentication extends Command
{
    protected $signature = 'wyxos-authentication:install {--force : Replace the existing application config and recipient resolver}';

    protected $description = 'Install editable authentication config and an empty application-owned recipient resolver';

    public function handle(Filesystem $files): int
    {
        $namespace = rtrim($this->laravel->getNamespace(), '\\').'\\Security';
        $resolver = str_replace('{{ namespace }}', $namespace, $files->get(__DIR__.'/../../stubs/LoginNotificationRecipients.php.stub'));
        $targets = [
            config_path('wyxos-authentication.php') => $files->get(__DIR__.'/../../config/wyxos-authentication.php'),
            app_path('Security/LoginNotificationRecipients.php') => $resolver,
        ];
        foreach ($targets as $path => $content) {
            if ($files->exists($path) && ! $this->option('force')) {
                $this->line('Kept existing '.basename($path).'.');

                continue;
            }
            $files->ensureDirectoryExists(dirname($path));
            $files->put($path, $content);
            $this->line('Installed '.basename($path).'.');
        }
        $this->info('Review the recipient resolver before enabling LA_LOGIN_NOTIFICATIONS. Run migrations separately.');

        return self::SUCCESS;
    }
}
