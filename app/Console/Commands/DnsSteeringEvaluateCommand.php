<?php

namespace App\Console\Commands;

use App\Services\Dns\DnsSteeringPolicyService;
use App\Services\Dns\DnsZoneService;
use Illuminate\Console\Command;

class DnsSteeringEvaluateCommand extends Command
{
    protected $signature = 'dns:steering:evaluate
        {--apply : Apply safe plans for enabled policies}
        {--team= : Limit evaluation to a team ID}
        {--json : Emit the full structured result as JSON}';

    protected $description = 'Evaluate DNS steering failover policies and optionally apply enabled plans';

    public function handle(DnsSteeringPolicyService $steering, DnsZoneService $dnsZones): int
    {
        $apply = (bool) $this->option('apply');
        $teamId = filled($this->option('team')) ? (int) $this->option('team') : null;

        $result = $steering->evaluatePolicies($dnsZones, $apply, $teamId);

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return empty($result['errors']) ? self::SUCCESS : self::FAILURE;
        }

        $mode = $apply ? 'apply' : 'dry-run';
        $this->info("DNS steering evaluation complete ({$mode}).");
        $this->line("Policies evaluated: {$result['evaluated']}");
        $this->line('Plans: '.count($result['planned']));
        $this->line('Applied: '.count($result['applied']));
        $this->line('Skipped: '.count($result['skipped']));
        $this->line('Errors: '.count($result['errors']));

        foreach ($result['planned'] as $plan) {
            $this->line(sprintf(
                ' - %s %s reason=%s can_apply=%s actions=%d',
                $plan['domain'],
                $plan['strategy'],
                $plan['reason'],
                $plan['can_apply'] ? 'yes' : 'no',
                count($plan['actions']),
            ));
        }

        return empty($result['errors']) ? self::SUCCESS : self::FAILURE;
    }
}
