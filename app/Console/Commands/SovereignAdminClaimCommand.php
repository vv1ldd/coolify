<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\SovereignAdminClaimService;
use Illuminate\Console\Command;
use RuntimeException;

class SovereignAdminClaimCommand extends Command
{
    protected $signature = 'sovereign:admin-claim
        {--user-id= : Existing Coolify user id to bind}
        {--ttl=30 : Claim expiry in minutes}
        {--auto : Select user 0 or the only root-team admin/owner}
        {--base-url= : Public Coolify base URL for the printed claim link}';

    protected $description = 'Generate a one-time SL1 claim link for an existing Coolify admin user';

    public function handle(SovereignAdminClaimService $claims): int
    {
        try {
            $user = $this->resolveUser($claims);
            if (! $user) {
                $this->warn('No unbound instance admin could be selected automatically.');
                $this->line('Run with --user-id after choosing from:');
                $this->printCandidates($claims);

                return 2;
            }

            $ttl = max(1, (int) $this->option('ttl'));
            $result = $claims->createForUser($user, $ttl, 'DID:SYS|SERVICE:#sovereign-upgrade-script');
            $url = $this->claimUrl($result['token']);

            $this->info("Generated one-time SL1 admin claim for user #{$user->id} ({$user->email}).");
            $this->line("CLAIM_URL={$url}");
            $this->line('CLAIM_EXPIRES_AT='.$result['claim']->expires_at->toIso8601String());
            $this->line('Open the claim URL, complete SL1 Identity, and the existing admin row will be bound to that SL1 identity.');

            return 0;
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return 1;
        }
    }

    private function resolveUser(SovereignAdminClaimService $claims): ?User
    {
        $userId = $this->option('user-id');
        if ($userId !== null && $userId !== '') {
            $user = User::with('teams')->find((int) $userId);
            if (! $user) {
                throw new RuntimeException("User #{$userId} was not found.");
            }

            return $user;
        }

        if ($this->option('auto')) {
            return $claims->autoCandidate();
        }

        return null;
    }

    private function printCandidates(SovereignAdminClaimService $claims): void
    {
        $candidates = $claims->instanceAdminCandidates();
        if ($candidates->isEmpty()) {
            $this->line('  no unbound root-team admin/owner users found');

            return;
        }

        foreach ($candidates as $candidate) {
            $role = $candidate->roleInTeam(0) ?? 'unknown';
            $this->line("  --user-id={$candidate->id} {$candidate->email} ({$role})");
        }
    }

    private function claimUrl(string $token): string
    {
        $baseUrl = (string) ($this->option('base-url') ?: config('app.url', 'http://localhost'));
        $path = route('auth.sl1.admin-claim', ['token' => $token], false);

        return rtrim($baseUrl, '/').$path;
    }
}
