<?php

namespace App\Services;

use App\Models\Sl1NotificationEnvelope;
use App\Models\TeamInvitationArtifact;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class Sl1NotificationEnvelopeService
{
    public const VERSION = 'sl1.notification.v1';

    public const TYPE_TEAM_INVITATION = 'team.invitation';

    public function publishTeamInvitationDiscovery(
        TeamInvitationArtifact $artifact,
        array $deliveryChannels = ['sl1.inbox'],
        ?User $actor = null,
    ): Sl1NotificationEnvelope {
        if ($artifact->artifact_version !== TeamInvitationArtifactService::ARTIFACT_VERSION) {
            throw new InvalidArgumentException('Unsupported invitation artifact version.');
        }

        return DB::transaction(function () use ($artifact, $deliveryChannels, $actor) {
            $recipientHint = $this->recipientHintFor($artifact);
            $artifactRef = $this->artifactRefFor($artifact);
            $issuerEntity = $artifact->issued_by_entity_address ?: $actor?->sl1IdentityBinding?->entity_address;
            $replayKey = hash('sha256', $this->canonicalJson([
                'version' => self::VERSION,
                'notification_type' => self::TYPE_TEAM_INVITATION,
                'recipient_hint' => $recipientHint,
                'artifact_ref' => $artifactRef,
                'issuer_entity_address' => $issuerEntity,
            ]));

            $envelope = Sl1NotificationEnvelope::create([
                'envelope_version' => self::VERSION,
                'notification_type' => self::TYPE_TEAM_INVITATION,
                'team_id' => $artifact->team_id,
                'team_invitation_artifact_id' => $artifact->id,
                'recipient_hint' => $recipientHint,
                'artifact_ref' => $artifactRef,
                'issuer_entity_address' => $issuerEntity,
                'status' => 'pending',
                'delivery_channels' => array_values(array_unique($deliveryChannels)),
                'subject' => 'Team invitation discovery notice',
                'body' => 'A team invitation artifact exists. Use SL1 Identity to evaluate and consume it.',
                'authority_effect' => 'none',
                'non_authoritative' => true,
                'consumes_artifact' => false,
                'mutates_authority' => false,
                'capabilities_granted' => [],
                'replay_key' => $replayKey,
                'expires_at' => $artifact->expires_at,
                'meta' => [
                    'artifact_type' => 'NotificationEnvelope',
                    'version' => self::VERSION,
                    'theorem' => 'transport_disclosure_never_implies_authority_transfer',
                    'delivery_is_identity' => false,
                ],
            ]);

            app(InfraLedgerService::class)->record(
                eventType: self::VERSION,
                entity: $artifact->team,
                payload: [
                    'notification_id' => $envelope->uuid,
                    'notification_type' => $envelope->notification_type,
                    'artifact_ref' => $envelope->artifact_ref,
                    'recipient_hint' => $this->redactedRecipientHint($envelope->recipient_hint),
                    'delivery_channels' => $envelope->delivery_channels,
                ],
                inputState: [
                    'team_invitation_artifact_id' => $artifact->uuid,
                    'artifact_status' => $artifact->status,
                ],
                outputState: [
                    'status' => $envelope->status,
                    'authority_effect' => 'none',
                    'artifact_consumed' => false,
                    'membership_created' => false,
                    'role_mutated' => false,
                ],
                actor: 'DID:SYS|USER:#'.($actor?->id ?? $artifact->issued_by_user_id ?? 'system'),
                teamId: $artifact->team_id,
            );

            return $envelope;
        });
    }

    public function markRead(Sl1NotificationEnvelope $envelope): Sl1NotificationEnvelope
    {
        return $envelope->forceFill([
            'status' => 'read',
            'read_at' => now(),
        ])->save() ? $envelope : $envelope;
    }

    public function dismiss(Sl1NotificationEnvelope $envelope): Sl1NotificationEnvelope
    {
        return $envelope->forceFill([
            'status' => 'dismissed',
            'dismissed_at' => now(),
        ])->save() ? $envelope : $envelope;
    }

    private function recipientHintFor(TeamInvitationArtifact $artifact): array
    {
        $hint = $artifact->invited_principal_hint;
        if (is_string($hint) && str_starts_with($hint, 'sl1e_')) {
            return ['type' => 'entity', 'value' => $hint];
        }

        if (is_string($hint) && $hint !== '') {
            return ['type' => 'alias', 'value' => $hint];
        }

        if ($artifact->delivery_email_hash) {
            return ['type' => 'email_hash', 'value' => $artifact->delivery_email_hash];
        }

        return ['type' => 'external', 'value' => 'undisclosed'];
    }

    private function artifactRefFor(TeamInvitationArtifact $artifact): array
    {
        return [
            'object_type' => 'TeamInvitationArtifact',
            'id' => $artifact->uuid,
            'version' => $artifact->artifact_version,
            'hash' => hash('sha256', $this->canonicalJson([
                'id' => $artifact->uuid,
                'version' => $artifact->artifact_version,
                'team_id' => $artifact->team_id,
                'role_scope' => $artifact->role_scope,
                'expires_at' => $artifact->expires_at?->toIso8601String(),
            ])),
        ];
    }

    private function redactedRecipientHint(array $recipientHint): array
    {
        if (($recipientHint['type'] ?? null) === 'email_hash') {
            return $recipientHint;
        }

        return [
            'type' => $recipientHint['type'] ?? 'unknown',
            'value_hash' => hash('sha256', (string) ($recipientHint['value'] ?? '')),
        ];
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode($this->canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(fn ($entry) => $this->canonicalize($entry), $value);
            }

            ksort($value);

            return array_map(fn ($entry) => $this->canonicalize($entry), $value);
        }

        return $value;
    }
}
