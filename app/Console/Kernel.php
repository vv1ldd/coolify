<?php

namespace App\Console;

use App\Jobs\ApiTokenExpirationWarningJob;
use App\Jobs\CheckForUpdatesJob;
use App\Jobs\CheckHelperImageJob;
use App\Jobs\CheckTraefikVersionJob;
use App\Jobs\CleanupInstanceStuffsJob;
use App\Jobs\CleanupOrphanedPreviewContainersJob;
use App\Jobs\PullChangelog;
use App\Jobs\PullTemplatesFromCDN;
use App\Jobs\RegenerateSslCertJob;
use App\Jobs\ScheduledJobManager;
use App\Jobs\ServerManagerJob;
use App\Jobs\UpdateCoolifyJob;
use App\Models\InstanceSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    private Schedule $scheduleInstance;

    private InstanceSettings $settings;

    private string $updateCheckFrequency;

    private string $instanceTimezone;

    protected function schedule(Schedule $schedule): void
    {
        $this->scheduleInstance = $schedule;
        $this->settings = instanceSettings();
        $this->updateCheckFrequency = $this->settings->update_check_frequency ?: '0 * * * *';

        $this->instanceTimezone = $this->settings->instance_timezone ?: config('app.timezone');

        if (validate_timezone($this->instanceTimezone) === false) {
            $this->instanceTimezone = config('app.timezone');
        }

        // $this->scheduleInstance->job(new CleanupStaleMultiplexedConnections)->hourly();
        $this->scheduleInstance->command('cleanup:redis --clear-locks')->daily();
        $this->scheduleInstance->command('sanctum:prune-expired --hours=1')->hourly()->onOneServer();
        $this->scheduleInstance->job(new ApiTokenExpirationWarningJob)->hourly()->onOneServer();

        if (isDev()) {
            // Instance Jobs
            $this->scheduleInstance->command('horizon:snapshot')->everyMinute();
            $this->scheduleInstance->job(new CleanupInstanceStuffsJob)->everyMinute()->onOneServer();
            $this->scheduleInstance->job(new CheckHelperImageJob)->everyTenMinutes()->onOneServer();

            // Server Jobs
            $this->scheduleInstance->job(new ServerManagerJob)->everyMinute()->onOneServer();

            // Scheduled Jobs (Backups & Tasks)
            $this->scheduleInstance->job(new ScheduledJobManager)->everyMinute()->onOneServer();

            $this->scheduleInstance->command('uploads:clear')->everyTwoMinutes();

        } else {
            // Instance Jobs
            $this->scheduleInstance->command('horizon:snapshot')->everyFiveMinutes();
            $this->scheduleInstance->command('cleanup:unreachable-servers')->daily()->onOneServer();

            $this->scheduleInstance->job(new PullTemplatesFromCDN)->cron($this->updateCheckFrequency)->timezone($this->instanceTimezone)->onOneServer();
            $this->scheduleInstance->job(new PullChangelog)->cron($this->updateCheckFrequency)->timezone($this->instanceTimezone)->onOneServer();

            $this->scheduleInstance->job(new CleanupInstanceStuffsJob)->everyTwoMinutes()->onOneServer();
            $this->scheduleUpdates();

            // Server Jobs
            $this->scheduleInstance->job(new ServerManagerJob)->everyMinute()->onOneServer();

            $this->pullImages();

            // Scheduled Jobs (Backups & Tasks)
            $this->scheduleInstance->job(new ScheduledJobManager)->everyMinute()->onOneServer();

            $this->scheduleInstance->job(new RegenerateSslCertJob)->twiceDaily();

            $this->scheduleInstance->job(new CheckTraefikVersionJob)->weekly()->sundays()->at('00:00')->timezone($this->instanceTimezone)->onOneServer();

            $this->scheduleInstance->command('cleanup:database --yes')->daily();
            $this->scheduleInstance->command('uploads:clear')->everyTwoMinutes();

            // Cleanup orphaned PR preview containers daily
            $this->scheduleInstance->job(new CleanupOrphanedPreviewContainersJob)->daily()->onOneServer();

            // ─── SOVEREIGN GOVERNANCE ───────────────────────────────────
            // Verify infra ledger chain integrity every 6 hours
            $this->scheduleInstance
                ->command('sovereign:verify-infra-ledger')
                ->everySixHours()
                ->onOneServer()
                ->withoutOverlapping();

            // Merkle root anchor: every 15 min (dry-run until LEDGER_L1_ANCHOR_ENABLED=true)
            $this->scheduleInstance
                ->command('sovereign:anchor-ledger')
                ->everyFifteenMinutes()
                ->onOneServer()
                ->withoutOverlapping();
            $this->scheduleSovereignFailover();
            // ────────────────────────────────────────────────────────────
        }
    }

    private function scheduleSovereignFailover(): void
    {
        if (filter_var(env('SOVEREIGN_CONTROL_PLANE_HEARTBEAT_SEND', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->scheduleInstance
                ->command('control-plane:heartbeat --send')
                ->everyMinute()
                ->withoutOverlapping();
        }

        $dnsMode = strtolower((string) env('SOVEREIGN_DNS_STEERING_SCHEDULE', 'off'));
        $simpleL1Mode = strtolower((string) env('SOVEREIGN_SIMPLE_L1_FAILOVER_SCHEDULE', 'off'));
        if (in_array($simpleL1Mode, ['dry-run', 'dry_run'], true)) {
            $this->scheduleInstance
                ->command('sovereign:simple-l1-failover --json')
                ->everyMinute()
                ->withoutOverlapping();
        }

        if ($simpleL1Mode === 'apply') {
            $this->scheduleInstance
                ->command('sovereign:simple-l1-failover --apply --json')
                ->everyMinute()
                ->withoutOverlapping();
        }

        if (in_array($dnsMode, ['dry-run', 'dry_run'], true)) {
            $this->scheduleInstance
                ->command('dns:steering:evaluate --json')
                ->everyMinute()
                ->withoutOverlapping();
        }

        if ($dnsMode === 'apply') {
            $this->scheduleInstance
                ->command('dns:steering:evaluate --apply --json')
                ->everyMinute()
                ->withoutOverlapping();
        }
    }

    private function pullImages(): void
    {
        $this->scheduleInstance->job(new CheckHelperImageJob)
            ->cron($this->updateCheckFrequency)
            ->timezone($this->instanceTimezone)
            ->onOneServer();
    }

    private function scheduleUpdates(): void
    {
        $this->scheduleInstance->job(new CheckForUpdatesJob)
            ->cron($this->updateCheckFrequency)
            ->timezone($this->instanceTimezone)
            ->onOneServer();

        if ($this->settings->is_auto_update_enabled) {
            $autoUpdateFrequency = $this->settings->auto_update_frequency;
            $this->scheduleInstance->job(new UpdateCoolifyJob)
                ->cron($autoUpdateFrequency)
                ->timezone($this->instanceTimezone)
                ->onOneServer();
        }
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
