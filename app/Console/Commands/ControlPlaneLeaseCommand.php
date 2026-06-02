<?php

namespace App\Console\Commands;

use App\Models\ExecutionAuthorityLease;
use App\Services\ControlPlane\ControlPlaneAuthorityService;
use Illuminate\Console\Command;

class ControlPlaneLeaseCommand extends Command
{
    protected $signature = 'control-plane:lease
        {action : acquire, release, status, or expire}
        {--team= : Team ID}
        {--scope-type=global : Lease scope type}
        {--scope-key=global : Lease scope key}
        {--peer= : Holder peer UUID for acquire}
        {--ttl=60 : Lease TTL in seconds}
        {--token= : Lease token for release}
        {--reason= : Operator reason stored in metadata}
        {--json : Emit structured JSON}';

    protected $description = 'Manage explicit control-plane execution authority leases';

    public function handle(ControlPlaneAuthorityService $authority): int
    {
        $action = strtolower((string) $this->argument('action'));
        $teamId = filled($this->option('team')) ? (int) $this->option('team') : null;

        $result = match ($action) {
            'acquire' => $this->acquire($authority, $teamId),
            'release' => $this->release($authority, $teamId),
            'status' => $this->status($authority, $teamId),
            'expire' => [
                'ok' => true,
                'status' => 'expired',
                'expired' => $authority->expireStaleLeases($teamId),
            ],
            default => [
                'ok' => false,
                'status' => 'invalid_action',
                'message' => 'Action must be one of acquire, release, status, or expire.',
            ],
        };

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return (bool) data_get($result, 'ok') ? self::SUCCESS : self::FAILURE;
        }

        $this->renderResult($result);

        return (bool) data_get($result, 'ok') ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, mixed>
     */
    private function acquire(ControlPlaneAuthorityService $authority, ?int $teamId): array
    {
        if (! $teamId || ! filled($this->option('peer'))) {
            return [
                'ok' => false,
                'status' => 'missing_arguments',
                'message' => '--team and --peer are required for acquire.',
            ];
        }

        $result = $authority->acquireLease(
            team: $teamId,
            scope: $this->scope(),
            peer: (string) $this->option('peer'),
            ttlSeconds: (int) $this->option('ttl'),
            reason: filled($this->option('reason')) ? (string) $this->option('reason') : null,
        );

        if ((bool) data_get($result, 'ok') && data_get($result, 'lease') instanceof ExecutionAuthorityLease) {
            $lease = $result['lease'];
            $result['lease'] = array_merge($authority->publicLease($lease), [
                'lease_token' => $lease->lease_token,
            ]);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function release(ControlPlaneAuthorityService $authority, ?int $teamId): array
    {
        if (! $teamId || ! filled($this->option('token'))) {
            return [
                'ok' => false,
                'status' => 'missing_arguments',
                'message' => '--team and --token are required for release.',
            ];
        }

        $result = $authority->releaseLease(
            team: $teamId,
            scope: $this->scope(),
            token: (string) $this->option('token'),
            reason: filled($this->option('reason')) ? (string) $this->option('reason') : null,
        );

        if (data_get($result, 'lease') instanceof ExecutionAuthorityLease) {
            $result['lease'] = $authority->publicLease($result['lease']);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function status(ControlPlaneAuthorityService $authority, ?int $teamId): array
    {
        return [
            'ok' => true,
            'status' => 'active_leases',
            'leases' => $authority->activeLeases($teamId)
                ->map(fn (ExecutionAuthorityLease $lease): array => $authority->publicLease($lease))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{type: string, key: string}
     */
    private function scope(): array
    {
        return [
            'type' => (string) $this->option('scope-type'),
            'key' => (string) $this->option('scope-key'),
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function renderResult(array $result): void
    {
        if (! (bool) data_get($result, 'ok')) {
            $this->error((string) (data_get($result, 'message') ?: data_get($result, 'reason') ?: data_get($result, 'status')));

            return;
        }

        if (data_get($result, 'status') === 'active_leases') {
            $leases = data_get($result, 'leases', []);
            if ($leases === []) {
                $this->info('No active execution authority leases.');

                return;
            }

            $this->table(
                ['UUID', 'Team', 'Scope', 'Holder', 'Acquired', 'Expires'],
                collect($leases)->map(fn (array $lease): array => [
                    $lease['uuid'],
                    $lease['team_id'],
                    $lease['scope_type'].':'.$lease['scope_key'],
                    $lease['holder_peer_uuid'],
                    $lease['acquired_at'],
                    $lease['expires_at'],
                ])->all(),
            );

            return;
        }

        $this->info((string) data_get($result, 'status'));
        if (data_get($result, 'lease.lease_token')) {
            $this->line('Lease token: '.data_get($result, 'lease.lease_token'));
        }
        if (data_get($result, 'expired') !== null) {
            $this->line('Expired leases: '.data_get($result, 'expired'));
        }
    }
}
