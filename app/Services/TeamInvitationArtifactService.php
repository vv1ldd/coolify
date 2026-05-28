<?php

namespace App\Services;

use App\Models\PolicyDecision;
use App\Models\Team;
use App\Models\TeamInvitationArtifact;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TeamInvitationArtifactService
{
    public const ARTIFACT_VERSION = 'team.invitation.v1';

    public const CAPABILITY_TEAM_MEMBER_INVITE = 'team.member.invite';

    public function issueFromDecision(PolicyDecision $decision): TeamInvitationArtifact
    {
        $this->assertDecisionAllowsInvite($decision);

        return DB::transaction(function () use ($decision) {
            $team = Team::findOrFail($decision->target_id);
            $deliveryEmail = data_get($decision->scope, 'delivery_email');
            $issuer = $decision->user;
            $issuedByEntity = $issuer?->sl1IdentityBinding?->entity_address;

            $artifact = TeamInvitationArtifact::create([
                'artifact_version' => self::ARTIFACT_VERSION,
                'team_id' => $team->id,
                'policy_decision_id' => $decision->id,
                'issued_by_user_id' => $decision->user_id,
                'issued_by_entity_address' => $issuedByEntity,
                'role_scope' => data_get($decision->scope, 'role_scope'),
                'invited_principal_hint' => data_get($decision->scope, 'invited_principal_hint'),
                'delivery_email' => $deliveryEmail,
                'delivery_email_hash' => data_get($decision->scope, 'delivery_email_hash'),
                'replay_key' => hash('sha256', implode('|', [
                    self::ARTIFACT_VERSION,
                    $decision->uuid,
                    $team->id,
                    random_bytes(16),
                ])),
                'status' => 'issued',
                'expires_at' => $decision->expires_at,
                'meta' => [
                    'artifact_type' => 'TeamInvitationArtifact',
                    'version' => self::ARTIFACT_VERSION,
                    'delivery_is_identity' => false,
                    'consumable_in_phase' => false,
                ],
            ]);

            app(InfraLedgerService::class)->record(
                eventType: self::CAPABILITY_TEAM_MEMBER_INVITE,
                entity: $team,
                payload: [
                    'artifact_id' => $artifact->uuid,
                    'artifact_version' => $artifact->artifact_version,
                    'role_scope' => $artifact->role_scope,
                    'delivery_email_hash' => $artifact->delivery_email_hash,
                ],
                inputState: [
                    'policy_decision_id' => $decision->uuid,
                    'issued_by_user_id' => $decision->user_id,
                    'issued_by_entity_address' => $issuedByEntity,
                ],
                outputState: [
                    'status' => $artifact->status,
                    'membership_created' => false,
                    'consumable' => false,
                ],
                actor: 'DID:SYS|USER:#'.$decision->user_id,
                teamId: $team->id,
            );

            return $artifact;
        });
    }

    public function revoke(TeamInvitationArtifact $artifact, ?User $actor = null): TeamInvitationArtifact
    {
        if ($artifact->status !== 'issued') {
            throw new InvalidArgumentException('TeamInvitationArtifact is not revocable.');
        }

        return DB::transaction(function () use ($artifact, $actor) {
            $artifact->forceFill([
                'status' => 'revoked',
                'revoked_at' => now(),
            ])->save();

            app(InfraLedgerService::class)->record(
                eventType: self::CAPABILITY_TEAM_MEMBER_INVITE.'.revoked',
                entity: $artifact->team,
                payload: [
                    'artifact_id' => $artifact->uuid,
                    'artifact_version' => $artifact->artifact_version,
                    'role_scope' => $artifact->role_scope,
                ],
                inputState: [
                    'previous_status' => 'issued',
                ],
                outputState: [
                    'status' => $artifact->status,
                    'revoked_at' => $artifact->revoked_at?->toIso8601String(),
                ],
                actor: 'DID:SYS|USER:#'.($actor?->id ?? 'system'),
                teamId: $artifact->team_id,
            );

            return $artifact;
        });
    }

    private function assertDecisionAllowsInvite(PolicyDecision $decision): void
    {
        if (! $decision->allows(self::CAPABILITY_TEAM_MEMBER_INVITE)) {
            throw new InvalidArgumentException('PolicyDecision does not allow team invitation issuance.');
        }

        if ($decision->intent_type !== self::CAPABILITY_TEAM_MEMBER_INVITE) {
            throw new InvalidArgumentException('PolicyDecision intent mismatch.');
        }

        if ($decision->target_type !== Team::class) {
            throw new InvalidArgumentException('PolicyDecision target mismatch.');
        }

        if ((int) data_get($decision->scope, 'team_id') !== (int) $decision->target_id) {
            throw new InvalidArgumentException('PolicyDecision team scope mismatch.');
        }

        if (! in_array(data_get($decision->scope, 'role_scope'), ['owner', 'admin', 'member'], true)) {
            throw new InvalidArgumentException('PolicyDecision role scope mismatch.');
        }
    }
}
