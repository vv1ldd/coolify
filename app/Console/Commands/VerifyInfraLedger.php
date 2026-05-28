<?php

namespace App\Console\Commands;

use App\Services\InfraLedgerService;
use Illuminate\Console\Command;

class VerifyInfraLedger extends Command
{
    protected $signature = 'sovereign:verify-infra-ledger
                            {--team=  : Verify a specific team ID (omit for system chain)}
                            {--limit= : Verify only the last N entries}';

    protected $description = 'Verify the cryptographic integrity of the Sovereign Infrastructure Ledger chain';

    public function handle(InfraLedgerService $ledger): int
    {
        $teamId = $this->option('team') ? (int) $this->option('team') : null;
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        $scope = $teamId ? "Team #{$teamId}" : 'System Chain';
        $this->info('┌─────────────────────────────────────────────────');
        $this->info('│  SOVEREIGN INFRASTRUCTURE LEDGER — INTEGRITY SCAN');
        $this->info("│  Scope : {$scope}");
        $this->info('│  Window: '.($limit ? "Last {$limit} entries" : 'Full chain'));
        $this->info('└─────────────────────────────────────────────────');

        $result = $ledger->verifyIntegrity($teamId, $limit);

        $this->newLine();
        $this->line("  Entries scanned : <fg=white>{$result['count']}</>");

        if ($result['valid']) {
            $this->line('  Chain status    : <fg=green;options=bold>✔ CONSENSUS REACHED — All fingerprints valid</>');
        } else {
            $this->line('  Chain status    : <fg=red;options=bold>✘ INTEGRITY VIOLATION DETECTED</>');
            $this->newLine();
            foreach ($result['errors'] as $err) {
                $this->error("  [{$err['type']}] #{$err['id']}: {$err['detail']}");
            }
        }

        $this->newLine();

        return $result['valid'] ? self::SUCCESS : self::FAILURE;
    }
}
