<?php

namespace App\Livewire;

use App\Models\InfraLedger;
use App\Models\PendingIntent;
use App\Services\InfraLedgerService;
use App\Services\PolicyEngine;
use Livewire\Component;

class InfraLedgerIndex extends Component
{
    public int $perPage = 25;

    public ?string $filterType = null;

    public ?string $integrityStatus = null;

    public bool $integrityChecked = false;

    public function verifyChain(): void
    {
        $result = app(InfraLedgerService::class)->verifyIntegrity(
            teamId: currentTeam()?->id
        );

        $this->integrityStatus = $result['valid'] ? 'valid' : 'violated';
        $this->integrityChecked = true;
    }

    public function coSign(int $intentId): void
    {
        $intent = PendingIntent::find($intentId);
        if (! $intent || $intent->status !== PendingIntent::STATUS_PENDING) {
            $this->dispatch('error', 'Action failed', 'Pending intent not found or already executed.');

            return;
        }

        $actorDid = 'DID:SYS|USER:#'.(auth()->id() ?? 'system');

        // Deduce signature role: admin or security or developer
        $role = isInstanceAdmin() ? 'security' : 'developer';
        if (isset($intent->signatures[$actorDid])) {
            $this->dispatch('warning', 'Already signed', 'You have already co-signed this operational intent.');

            return;
        }

        $signed = app(PolicyEngine::class)->addSignature($intent, $actorDid, $role);

        if ($signed) {
            $this->dispatch('success', 'Intent Co-Signed', 'Your cryptographic approval was appended to the intent payload.');
        }
    }

    public function cancelIntent(int $intentId): void
    {
        $this->revokeIntent($intentId);
    }

    public function revokeIntent(int $intentId): void
    {
        $intent = PendingIntent::query()
            ->whereKey($intentId)
            ->when(currentTeam()?->id, fn ($query, $teamId) => $query->where('team_id', $teamId))
            ->first();

        if (! $intent || $intent->status !== PendingIntent::STATUS_PENDING) {
            $this->dispatch('error', 'Action failed', 'Pending intent not found or already closed.');

            return;
        }

        app(PolicyEngine::class)->cancelIntent(
            $intent,
            'DID:SYS|USER:#'.(auth()->id() ?? 'system'),
            'Operator marked this pending intent as no longer current.'
        );

        $this->dispatch('success', 'Intent revoked', 'The intent was removed from the pending mempool.');
    }

    public function expireStaleIntents(): void
    {
        $count = app(PolicyEngine::class)->expireStalePendingIntents(currentTeam()?->id);

        $this->dispatch('success', 'Stale intents expired', "{$count} pending intent(s) were closed by TTL.");
    }

    public function render()
    {
        $teamId = currentTeam()?->id;
        app(PolicyEngine::class)->expireStalePendingIntents($teamId);

        $entries = InfraLedger::query()
            ->when($teamId, fn ($q) => $q->where('team_id', $teamId))
            ->when($this->filterType, fn ($q) => $q->where('event_type', $this->filterType))
            ->orderBy('id', 'desc')
            ->paginate($this->perPage);

        $eventTypes = InfraLedger::query()
            ->when($teamId, fn ($q) => $q->where('team_id', $teamId))
            ->select('event_type')
            ->distinct()
            ->orderBy('event_type')
            ->pluck('event_type');

        // Load active mempool intents waiting for signatures
        $pendingIntents = PendingIntent::query()
            ->when($teamId, fn ($q) => $q->where('team_id', $teamId))
            ->where('status', PendingIntent::STATUS_PENDING)
            ->orderBy('created_at', 'desc')
            ->get();

        return view('livewire.infra-ledger.index', [
            'entries' => $entries,
            'eventTypes' => $eventTypes,
            'pendingIntents' => $pendingIntents,
        ])->title('Audit Ledger | Sovereign');
    }
}
