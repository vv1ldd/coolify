<?php

namespace App\Services;

use App\Models\Sl1Controller;
use App\Models\Sl1Entity;
use App\Models\Sl1PeerIdentity;
use App\Models\Sl1PeerNode;
use App\Models\Sl1PeerObservedEvent;

class Sl1AuthorityAdmissibilityService
{
    public function __construct(
        private readonly Sl1NodeIdentityService $nodeIdentity,
        private readonly Sl1AuthorityPolicyService $policy,
        private readonly Sl1AuthorityProjectionCandidateService $projectionCandidates,
    ) {}

    /**
     * Evaluate observed evidence without projecting it into local authority state.
     *
     * @return array{ok: bool, status: string, report: array<string, mixed>}
     */
    public function dryRun(Sl1PeerObservedEvent $observed): array
    {
        $observed->loadMissing('peer');

        $report = [
            'mode' => 'dry_run',
            'authority_projection' => 'unchanged',
            'checks' => [
                'peer_verified' => $this->peerIsVerified($observed->peer),
                'envelope_shape' => $this->hasRequiredEnvelope($observed),
                'hash_matches_envelope' => $this->hashMatchesEnvelope($observed),
            ],
            'crypto_context' => [
                'signer_known' => $this->signerKnown($observed),
                'signature_valid' => $this->signatureValid($observed),
                'decision_effect' => 'report_only',
            ],
            'binding_context' => $this->bindingContext($observed),
            'causal_context' => $this->causalContext($observed),
        ];
        $report['policy_context'] = $this->policy->dryRun($report);
        $report['projection_candidate'] = $this->projectionCandidates->fromReport($observed, $report);

        $ok = ! in_array(false, $report['checks'], true);
        $status = $ok
            ? Sl1PeerObservedEvent::STATUS_DRY_RUN_ADMISSIBLE
            : Sl1PeerObservedEvent::STATUS_DRY_RUN_REJECTED;

        $observed->forceFill([
            'admissibility_status' => $status,
            'admissibility_report' => $report,
            'admissibility_evaluated_at' => now(),
        ])->save();

        return [
            'ok' => $ok,
            'status' => $status,
            'report' => $report,
        ];
    }

    private function peerIsVerified(?Sl1PeerNode $peer): bool
    {
        return $peer?->status === Sl1PeerNode::STATUS_VERIFIED;
    }

    private function signerKnown(Sl1PeerObservedEvent $observed): bool
    {
        $signerNodeId = data_get($observed->remote_envelope, 'node_signature.node_id')
            ?: data_get($observed->remote_envelope, 'signer_node_id');

        if (! filled($signerNodeId)) {
            return false;
        }

        return Sl1PeerIdentity::query()
            ->where('sl1_peer_node_id', $observed->sl1_peer_node_id)
            ->where('peer_node_id', (string) $signerNodeId)
            ->whereIn('trust_state', [
                Sl1PeerIdentity::TRUST_OBSERVED,
                Sl1PeerIdentity::TRUST_VERIFIED,
            ])
            ->exists();
    }

    private function signatureValid(Sl1PeerObservedEvent $observed): ?bool
    {
        $identity = $this->signerIdentity($observed);
        if (! $identity) {
            return null;
        }

        return $this->nodeIdentity->verifyEventEnvelope(
            $observed->remote_envelope,
            $identity->peer_node_id,
            (string) data_get($identity->metadata, 'issuer', $observed->peer?->issuer ?? ''),
            $identity->peer_public_key
        );
    }

    private function signerIdentity(Sl1PeerObservedEvent $observed): ?Sl1PeerIdentity
    {
        $signerNodeId = data_get($observed->remote_envelope, 'node_signature.node_id')
            ?: data_get($observed->remote_envelope, 'signer_node_id');

        if (! filled($signerNodeId)) {
            return null;
        }

        return Sl1PeerIdentity::query()
            ->where('sl1_peer_node_id', $observed->sl1_peer_node_id)
            ->where('peer_node_id', (string) $signerNodeId)
            ->whereIn('trust_state', [
                Sl1PeerIdentity::TRUST_OBSERVED,
                Sl1PeerIdentity::TRUST_VERIFIED,
            ])
            ->first();
    }

    private function hasRequiredEnvelope(Sl1PeerObservedEvent $observed): bool
    {
        return $observed->remote_event_hash !== ''
            && $observed->event_type !== ''
            && filled($observed->entity_address)
            && is_array($observed->remote_envelope)
            && data_get($observed->remote_envelope, 'event_hash') === $observed->remote_event_hash
            && data_get($observed->remote_envelope, 'event_type') === $observed->event_type;
    }

    private function hashMatchesEnvelope(Sl1PeerObservedEvent $observed): bool
    {
        if ($observed->event_type !== 'sl1.identity.proof.observed') {
            return false;
        }

        $expectedHash = hash('sha256', $this->canonicalJson([
            'event_type' => $observed->event_type,
            'entity_address' => $observed->entity_address,
            'controller_address' => $observed->controller_address ?: null,
            'proof_id' => data_get($observed->remote_envelope, 'proof_id'),
            'payload' => $observed->remote_payload ?? [],
        ]));

        return hash_equals($expectedHash, $observed->remote_event_hash);
    }

    /**
     * @return array<string, mixed>
     */
    private function bindingContext(Sl1PeerObservedEvent $observed): array
    {
        $proof = $this->remoteProof($observed);
        $identity = $this->remoteIdentity($observed);
        $claimedEntity = $this->firstFilled([
            data_get($proof, 'entity_l1_address'),
            data_get($identity, 'entity_l1_address'),
        ]);
        $claimedController = $this->firstFilled([
            data_get($proof, 'controller_l1_address'),
            data_get($proof, 'keyAddress'),
            data_get($identity, 'key_l1_address'),
        ]);
        $claimedProofId = $this->firstFilled([
            data_get($proof, 'proof_id'),
            data_get($proof, 'proofId'),
        ]);

        $entityMatches = filled($claimedEntity) ? $claimedEntity === $observed->entity_address : null;
        $controllerMatches = filled($claimedController) && filled($observed->controller_address)
            ? $claimedController === $observed->controller_address
            : null;
        $proofIdMatches = filled($claimedProofId) && filled(data_get($observed->remote_envelope, 'proof_id'))
            ? $claimedProofId === data_get($observed->remote_envelope, 'proof_id')
            : null;

        return [
            'controller_binding_valid' => $this->nullableAllTrue([$entityMatches, $controllerMatches, $proofIdMatches]),
            'event_entity_matches_proof' => $entityMatches,
            'event_controller_matches_proof' => $controllerMatches,
            'event_proof_id_matches_proof' => $proofIdMatches,
            'credential_material_present' => filled($this->firstFilled([
                data_get($proof, 'credential_id'),
                data_get($proof, 'credential_public_key'),
                data_get($identity, 'credential_id'),
                data_get($identity, 'credentialId'),
                data_get($identity, 'credential_public_key'),
                data_get($identity, 'credentialPublicKey'),
            ])),
            'local_controller_known' => $this->localControllerKnown($observed),
            'decision_effect' => 'report_only',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function remoteProof(Sl1PeerObservedEvent $observed): array
    {
        $proof = data_get($observed->remote_payload, 'proof', []);

        return is_array($proof) ? $proof : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function remoteIdentity(Sl1PeerObservedEvent $observed): array
    {
        $identity = data_get($observed->remote_payload, 'identity', []);

        return is_array($identity) ? $identity : [];
    }

    private function localControllerKnown(Sl1PeerObservedEvent $observed): bool
    {
        if (! filled($observed->entity_address) || ! filled($observed->controller_address)) {
            return false;
        }

        return Sl1Controller::query()
            ->where('entity_address', $observed->entity_address)
            ->where('controller_address', $observed->controller_address)
            ->where('status', 'active')
            ->exists();
    }

    /**
     * @param  array<int, mixed>  $values
     */
    private function firstFilled(array $values): ?string
    {
        foreach ($values as $value) {
            if (filled($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * @param  array<int, bool|null>  $values
     */
    private function nullableAllTrue(array $values): ?bool
    {
        $known = array_filter($values, fn ($value) => $value !== null);
        if ($known === []) {
            return null;
        }

        return ! in_array(false, $known, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function causalContext(Sl1PeerObservedEvent $observed): array
    {
        $entity = Sl1Entity::query()
            ->where('entity_address', $observed->entity_address)
            ->first();
        $remotePreviousHash = data_get($observed->remote_envelope, 'previous_event_hash');

        return [
            'local_entity_known' => $entity !== null,
            'local_current_event_hash' => $entity?->current_event_hash,
            'remote_previous_event_hash' => $remotePreviousHash,
            'remote_extends_local_tip' => $entity !== null
                && $entity->current_event_hash !== null
                && $remotePreviousHash === $entity->current_event_hash,
            'decision_effect' => 'report_only',
        ];
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode(
            $this->sortCanonicalValue($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    private function sortCanonicalValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn ($item) => $this->sortCanonicalValue($item), $value);
        }

        ksort($value);

        return array_map(fn ($item) => $this->sortCanonicalValue($item), $value);
    }
}
