<?php

namespace Wyxos\LaravelAuthentication\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Wyxos\LaravelAuthentication\Models\AuthenticationDevice;
use Wyxos\LaravelAuthentication\Models\AuthenticationEntry;
use Wyxos\LaravelAuthentication\Models\AuthenticationIdentity;

class PruneAuthentication extends Command
{
    protected $signature = 'wyxos-authentication:prune';

    protected $description = 'Remove authentication history and known devices past the configured retention period';

    public function handle(): int
    {
        $days = max(1, (int) config('wyxos-authentication.retention_days', 60));
        $cutoff = now()->subDays($days);
        DB::transaction(function () use ($cutoff): void {
            AuthenticationEntry::where('login_at', '<', $cutoff)->delete();
            AuthenticationDevice::where('last_seen_at', '<', $cutoff)->delete();
            AuthenticationIdentity::where('updated_at', '<', $cutoff)
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('wyxos_authentication_devices')->whereColumn('identity_id', 'wyxos_authentication_identities.id'))
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('wyxos_authentication_log')->whereColumn('authenticatable_id', 'wyxos_authentication_identities.id'))
                ->delete();
        });
        $this->info('Expired authentication records removed.');

        return self::SUCCESS;
    }
}
