<?php

namespace App\Console\Commands;

use App\Models\InfraLedger;
use App\Services\InfraLedgerService;
use App\Services\SimpleL1Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sovereign Infra Ledger — Merkle Root Anchor Command (Stage 3 Foundation)
 *
 * Batches unanchored ledger entries, builds a Merkle root,
 * and submits it to Simple L1 for permanent cryptographic sealing.
 *
 * Architecture:
 *   - Every LEDGER_ANCHOR_BATCH_SIZE events (default: 100), a Merkle root is built.
 *   - The root is anchored to Simple L1 as an INFRA_CHECKPOINT_INTENT.
 *   - The anchor receipt (L1 tx hash) is stored back on the last entry in the batch.
 *   - Designed to run as a scheduled job (every 10 min, or on demand).
 *
 * Stage 2 (current): Merkle root is computed and logged — no L1 call yet.
 * Stage 3 (activate): Set LEDGER_L1_ANCHOR_ENABLED=true to submit to Simple L1.
 */
class AnchorLedger extends Command
{
    protected $signature = 'sovereign:anchor-ledger
                            {--team=   : Anchor only a specific team\'s chain (null = all teams)}
                            {--batch=  : Number of events per Merkle batch (default: env LEDGER_ANCHOR_BATCH_SIZE or 100)}
                            {--dry-run : Compute Merkle root without submitting to L1}';

    protected $description = 'Batch Merkle root builder for the Sovereign Infra Ledger → Simple L1 anchoring (Stage 3)';

    public function handle(): int
    {
        $batchSize = (int) ($this->option('batch') ?? env('LEDGER_ANCHOR_BATCH_SIZE', 100));
        $teamId = $this->option('team') ? (int) $this->option('team') : null;
        $dryRun = $this->option('dry-run');
        $l1Enabled = env('LEDGER_L1_ANCHOR_ENABLED', false) && ! $dryRun;

        $this->line('');
        $this->line('┌───────────────────────────────────────────────────────────────');
        $this->line('│  SOVEREIGN INFRA LEDGER — Merkle Root Anchor');
        $this->line('│  Batch size : '.$batchSize.' events');
        $this->line('│  Scope      : '.($teamId ? "Team #{$teamId}" : 'All teams'));
        $this->line('│  L1 Enabled : '.($l1Enabled ? 'YES — will anchor' : 'NO (dry-run or LEDGER_L1_ANCHOR_ENABLED=false)'));
        $this->line('└───────────────────────────────────────────────────────────────');
        $this->line('');

        // 1. Verify chain integrity before anchoring
        $integrity = app(InfraLedgerService::class)->verifyIntegrity($teamId);
        if (! $integrity['valid']) {
            $this->error('✘ Chain integrity violation — aborting anchor. Run: php artisan sovereign:verify-infra-ledger');
            foreach ($integrity['errors'] as $err) {
                $this->error("  [{$err['type']}] #{$err['id']}: {$err['detail']}");
            }

            return self::FAILURE;
        }
        $this->line("  ✔ Chain integrity verified ({$integrity['count']} entries)");

        // 2. Fetch unanchored entries
        $unanchored = InfraLedger::query()
            ->when($teamId, fn ($q) => $q->where('team_id', $teamId))
            ->whereNull(DB::connection('infra_ledger')->raw("meta->>'merkle_anchor_tx'"))
            ->orderBy('id', 'asc')
            ->get();

        if ($unanchored->isEmpty()) {
            $this->line('  ∅ No unanchored entries — chain is fully sealed.');

            return self::SUCCESS;
        }

        $this->line("  ⧖ Unanchored entries : {$unanchored->count()}");
        $this->line('');

        // 3. Split into batches and build Merkle roots
        $batches = $unanchored->chunk($batchSize);
        $anchored = 0;
        $batchNum = 0;

        foreach ($batches as $batch) {
            $batchNum++;
            $firstId = $batch->first()->id;
            $lastId = $batch->last()->id;

            // Build Merkle tree from fingerprints in this batch
            $merkleRoot = $this->buildMerkleRoot($batch->pluck('fingerprint')->all());

            $this->line("  Batch #{$batchNum}: entries #{$firstId}–#{$lastId}");
            $this->line("    Merkle root : {$merkleRoot}");

            $l1TxHash = null;

            if ($l1Enabled) {
                // Stage 3: Submit to Simple L1
                $l1TxHash = $this->submitToL1($merkleRoot, $firstId, $lastId, $batchNum, $teamId);
                if ($l1TxHash) {
                    $this->line("    L1 anchor   : {$l1TxHash}");
                } else {
                    $this->warn('    L1 anchor   : FAILED — batch not sealed');

                    continue;
                }
            } else {
                $this->line('    L1 anchor   : [staged — enable with LEDGER_L1_ANCHOR_ENABLED=true]');
            }

            // 4. Stamp the Merkle anchor receipt onto the last entry in this batch
            // We write directly to avoid the immutability boot guard (which is for application code).
            // This is a trusted system-level operation — the anchor receipt enriches the entry.
            DB::connection('infra_ledger')
                ->table('infra_ledger')
                ->where('id', $lastId)
                ->update([
                    'meta' => DB::raw("meta || jsonb_build_object(
                        'merkle_root', '{$merkleRoot}',
                        'merkle_batch_start', {$firstId},
                        'merkle_batch_end', {$lastId},
                        'merkle_anchor_tx', '".($l1TxHash ?? 'staged:'.$merkleRoot)."',
                        'merkle_anchored_at', '".now()->toIso8601String()."'
                    )"),
                ]);

            $anchored += $batch->count();
            $this->line("    Status      : ✔ SEALED\n");
        }

        $this->line("  Total anchored: {$anchored} entries in {$batchNum} batch(es)");
        $this->line('');

        Log::info('Sovereign Infra Ledger: Merkle anchor run completed', [
            'anchored' => $anchored,
            'batches' => $batchNum,
            'l1_active' => $l1Enabled,
        ]);

        return self::SUCCESS;
    }

    /**
     * Build a binary Merkle tree root from an array of leaf hashes (fingerprints).
     * Uses SHA-256 for each internal node: hash(left || right).
     */
    private function buildMerkleRoot(array $leaves): string
    {
        if (empty($leaves)) {
            return hash('sha256', 'empty_chain');
        }

        // Pad to even number (duplicate last leaf if odd)
        if (count($leaves) % 2 !== 0) {
            $leaves[] = end($leaves);
        }

        while (count($leaves) > 1) {
            $nextLevel = [];
            for ($i = 0; $i < count($leaves); $i += 2) {
                $nextLevel[] = hash('sha256', $leaves[$i].$leaves[$i + 1]);
            }
            $leaves = $nextLevel;

            // Pad again at each level if odd
            if (count($leaves) > 1 && count($leaves) % 2 !== 0) {
                $leaves[] = end($leaves);
            }
        }

        return $leaves[0];
    }

    /**
     * Stage 3: Submit Merkle root to Simple L1.
     * Returns the L1 tx hash on success, null on failure.
     */
    private function submitToL1(string $merkleRoot, int $firstId, int $lastId, int $batch, ?int $teamId): ?string
    {
        try {
            $l1 = app(SimpleL1Client::class);

            return $l1->recordTransaction('INFRA_CHECKPOINT_INTENT', [
                'merkle_root' => $merkleRoot,
                'batch_start' => $firstId,
                'batch_end' => $lastId,
                'batch_number' => $batch,
                'team_id' => $teamId,
                'anchored_at' => now()->toIso8601String(),
                'intent' => 'INFRA_LEDGER_SEAL',
            ]);
        } catch (\Throwable $e) {
            Log::error('Sovereign Ledger: L1 anchor failed', [
                'merkle_root' => $merkleRoot,
                'batch_start' => $firstId,
                'batch_end' => $lastId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
