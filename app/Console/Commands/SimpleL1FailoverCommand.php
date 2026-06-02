<?php

namespace App\Console\Commands;

use App\Services\SimpleL1\SimpleL1PanelFailoverService;
use Illuminate\Console\Command;

class SimpleL1FailoverCommand extends Command
{
    protected $signature = 'sovereign:simple-l1-failover
        {--team=0 : Team ID that owns the Simple L1 failover policy}
        {--domain= : Simple L1 domain to evaluate}
        {--apply : Promote a healthy node when the current A record target is unhealthy}
        {--json : Emit the structured result as JSON}';

    protected $description = 'Evaluate Simple L1 panel-node health and optionally promote Cloudflare DNS to a healthy node';

    public function handle(SimpleL1PanelFailoverService $failover): int
    {
        $result = $failover->evaluate(
            teamId: (int) $this->option('team'),
            domain: $this->option('domain') ?: null,
            apply: (bool) $this->option('apply'),
        );

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return data_get($result, 'ok') ? self::SUCCESS : self::FAILURE;
        }

        if (! data_get($result, 'ok')) {
            $this->error((string) data_get($result, 'message', 'Simple L1 failover is not configured.'));

            return self::FAILURE;
        }

        $this->info('Simple L1 failover evaluation complete.');
        $this->line('Domain: '.data_get($result, 'domain'));
        $this->line('Status: '.data_get($result, 'status'));
        $this->line('Current IP: '.(data_get($result, 'current_ip') ?: 'none'));
        $this->line('Current healthy: '.var_export(data_get($result, 'current_healthy'), true));
        $this->line('Target IP: '.(data_get($result, 'target_ip') ?: 'none'));
        $this->line('Can promote: '.(data_get($result, 'can_promote') ? 'yes' : 'no'));
        $this->line('Applied: '.(data_get($result, 'applied') ? 'yes' : 'no'));
        $this->line('Recommendation: '.data_get($result, 'decision.recommendation', 'n/a'));
        $this->line('Evidence hash: '.data_get($result, 'decision.evidence_hash', 'n/a'));

        return self::SUCCESS;
    }
}
