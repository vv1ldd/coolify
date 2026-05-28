<?php

namespace App\Services;

use App\Models\Sl1IdentityBinding;
use App\Models\SovereignAdminClaim;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class SovereignAdminClaimService
{
    public function __construct(private readonly InfraLedgerService $ledger) {}

    /**
     * @return array{claim: SovereignAdminClaim, token: string}
     */
    public function createForUser(User $user, int $ttlMinutes = 30, ?string $createdBy = null): array
    {
        return DB::transaction(function () use ($user, $ttlMinutes, $createdBy) {
            if (! $this->isInstanceAdmin($user)) {
                throw new RuntimeException("User #{$user->id} is not an instance admin.");
            }

            if ($user->sl1IdentityBinding()->exists()) {
                throw new RuntimeException("User #{$user->id} already has an SL1 identity binding.");
            }

            SovereignAdminClaim::query()
                ->where('user_id', $user->id)
                ->whereNull('claimed_at')
                ->update(['expires_at' => now()]);

            $token = Str::random(64);
            $claim = SovereignAdminClaim::create([
                'user_id' => $user->id,
                'claim_token_hash' => $this->hashToken($token),
                'expires_at' => now()->addMinutes(max(1, $ttlMinutes)),
                'created_by' => $createdBy ?? 'DID:SYS|SERVICE:#sovereign-admin-claim',
            ]);

            return ['claim' => $claim, 'token' => $token];
        });
    }

    public function assertPendingToken(string $token): SovereignAdminClaim
    {
        $claim = $this->findByToken($token);
        if (! $claim || ! $claim->isPending()) {
            throw new RuntimeException('Sovereign admin claim is invalid or expired.');
        }

        return $claim;
    }

    /**
     * @param  array{identity: array<string, mixed>, proof: array<string, mixed>}  $verified
     */
    public function claim(string $token, array $verified): User
    {
        return DB::transaction(function () use ($token, $verified) {
            $claim = SovereignAdminClaim::query()
                ->where('claim_token_hash', $this->hashToken($token))
                ->lockForUpdate()
                ->first();

            if (! $claim || ! $claim->isPending()) {
                throw new RuntimeException('Sovereign admin claim is invalid or expired.');
            }

            $user = $claim->user()->with('teams')->firstOrFail();
            if (! $this->isInstanceAdmin($user)) {
                throw new RuntimeException("Claim target user #{$user->id} is no longer an instance admin.");
            }

            $proof = $verified['proof'];
            $identity = $verified['identity'];
            $entityAddress = (string) (data_get($proof, 'entity_l1_address') ?: data_get($identity, 'entity_l1_address'));
            if ($entityAddress === '') {
                throw new RuntimeException('SL1 entity address is missing.');
            }

            $existingBinding = Sl1IdentityBinding::query()->where('entity_address', $entityAddress)->first();
            if ($existingBinding && (int) $existingBinding->user_id !== (int) $user->id) {
                throw new RuntimeException('SL1 identity is already bound to another Coolify user.');
            }

            $currentUserBinding = $user->sl1IdentityBinding()->first();
            if ($currentUserBinding && $currentUserBinding->entity_address !== $entityAddress) {
                throw new RuntimeException("Claim target user #{$user->id} already has a different SL1 identity binding.");
            }

            $bindingAttributes = $this->bindingAttributes($verified);
            if ($existingBinding) {
                $existingBinding->forceFill($bindingAttributes)->save();
            } else {
                Sl1IdentityBinding::create([
                    'user_id' => $user->id,
                    ...$bindingAttributes,
                ]);
            }

            $claim->forceFill([
                'claimed_at' => now(),
                'claimed_entity_address' => $entityAddress,
                'last_proof' => $proof,
            ])->save();

            $this->ledger->recordSystem('identity.admin.claimed', [
                'user_id' => $user->id,
                'entity_address' => $entityAddress,
                'claim_id' => $claim->id,
                'proof_id' => data_get($proof, 'proof_id') ?: data_get($proof, 'proofId'),
                'authority_model' => 'sovereign-admin-claim-v1',
            ]);

            return $user->fresh('teams');
        });
    }

    public function autoCandidate(): ?User
    {
        $root = User::with('teams')->find(0);
        if ($root && $this->isInstanceAdmin($root) && ! $root->sl1IdentityBinding()->exists()) {
            return $root;
        }

        $candidates = $this->instanceAdminCandidates();
        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        return null;
    }

    /**
     * @return Collection<int, User>
     */
    public function instanceAdminCandidates(): Collection
    {
        return User::query()
            ->whereDoesntHave('sl1IdentityBinding')
            ->whereHas('teams', function ($query) {
                $query->where('teams.id', 0)
                    ->whereIn('team_user.role', ['admin', 'owner']);
            })
            ->with('teams')
            ->orderBy('id')
            ->get();
    }

    private function findByToken(string $token): ?SovereignAdminClaim
    {
        return SovereignAdminClaim::query()
            ->where('claim_token_hash', $this->hashToken($token))
            ->first();
    }

    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    private function isInstanceAdmin(User $user): bool
    {
        $user->loadMissing('teams');

        return $user->isAdminOfTeam(0) || (int) $user->id === 0;
    }

    /**
     * @param  array{identity: array<string, mixed>, proof: array<string, mixed>}  $verified
     * @return array<string, mixed>
     */
    private function bindingAttributes(array $verified): array
    {
        $proof = $verified['proof'];
        $identity = $verified['identity'];

        return [
            'entity_address' => (string) (data_get($proof, 'entity_l1_address') ?: data_get($identity, 'entity_l1_address')),
            'controller_address' => data_get($proof, 'controller_l1_address') ?: data_get($identity, 'key_l1_address'),
            'alias' => data_get($proof, 'alias') ?: data_get($identity, 'alias'),
            'display_alias' => data_get($proof, 'display_alias') ?: data_get($identity, 'display_alias') ?: data_get($proof, 'displayName'),
            'proof_id' => data_get($proof, 'proof_id') ?: data_get($proof, 'proofId'),
            'last_proof' => $proof,
            'last_verified_at' => now(),
        ];
    }
}
