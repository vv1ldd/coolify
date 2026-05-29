<?php

namespace App\Console\Commands;

use App\Models\InstanceSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SovereignSyncIdentityPolicyCommand extends Command
{
    protected $signature = 'sovereign:sync-identity-policy';

    protected $description = 'Normalize instance confirmation policy for SL1-only identity';

    public function handle(): int
    {
        $settings = InstanceSettings::updateOrCreate(
            ['id' => 0],
            ['disable_two_step_confirmation' => true]
        );

        Cache::forget('instance_settings');
        Cache::forget('instance_settings_fqdn_host');

        $this->info('Legacy password/text confirmation is disabled.');
        $this->line('Sovereign destructive actions must be promoted to SL1 signed intents.');
        $this->line('Instance setting disable_two_step_confirmation='.(int) $settings->disable_two_step_confirmation);

        return 0;
    }
}
