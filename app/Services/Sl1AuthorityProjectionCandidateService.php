<?php

namespace App\Services;

use App\Models\Sl1PeerObservedEvent;

class Sl1AuthorityProjectionCandidateService
{
    /**
     * Build a non-authoritative projection preview from evaluation and policy facts.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    public function fromReport(Sl1PeerObservedEvent $observed, array $report): array
    {
        $projectionBlockers = $this->projectionBlockers($report);
        $commitBlockers = $this->commitBlockers($report);

        return [
            'mode' => 'shadow_only',
            'authority_projection' => 'unchanged',
            'would_project' => $projectionBlockers === [],
            'projection_allowed' => false,
            'projection_confidence' => $this->confidence($report, $projectionBlockers),
            'projection_blockers' => $projectionBlockers,
            'commit_blockers' => $commitBlockers,
            'candidate_view' => [
                'event_type' => $observed->event_type,
                'entity_address' => $observed->entity_address,
                'controller_address' => $observed->controller_address,
                'proof_id' => data_get($observed->remote_envelope, 'proof_id'),
                'event_hash' => $observed->remote_event_hash,
                'previous_event_hash' => data_get($observed->remote_envelope, 'previous_event_hash'),
            ],
            'decision_effect' => 'report_only',
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<int, string>
     */
    private function projectionBlockers(array $report): array
    {
        $blockers = [];
        if (! data_get($report, 'policy_context.readiness.structurally_admissible', false)) {
            $blockers[] = 'not_structurally_admissible';
        }

        $signatureValid = data_get($report, 'crypto_context.signature_valid');
        if ($signatureValid !== true) {
            $blockers[] = $signatureValid === false ? 'crypto_failed' : 'crypto_unknown';
        }

        $bindingValid = data_get($report, 'binding_context.controller_binding_valid');
        if ($bindingValid !== true) {
            $blockers[] = $bindingValid === false ? 'binding_failed' : 'binding_unknown';
        }

        if (! data_get($report, 'causal_context.remote_extends_local_tip', false)) {
            $blockers[] = 'causal_relation_unresolved';
        }

        return array_values(array_unique($blockers));
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<int, string>
     */
    private function commitBlockers(array $report): array
    {
        if (data_get($report, 'policy_context.projection_allowed') === true) {
            return [];
        }

        return [(string) data_get($report, 'policy_context.blocking_policy.code', 'projection_policy_blocked')];
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  array<int, string>  $projectionBlockers
     */
    private function confidence(array $report, array $projectionBlockers): string
    {
        if ($projectionBlockers === []) {
            return 'high';
        }
        if (! data_get($report, 'policy_context.readiness.structurally_admissible', false)) {
            return 'low';
        }
        if (in_array('crypto_failed', $projectionBlockers, true) || in_array('binding_failed', $projectionBlockers, true)) {
            return 'low';
        }

        return 'medium';
    }
}
