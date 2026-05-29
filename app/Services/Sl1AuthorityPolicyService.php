<?php

namespace App\Services;

class Sl1AuthorityPolicyService
{
    public const MODE_OBSERVE_ONLY = 'observe_only';

    /**
     * Synthesize local policy posture from evaluation facts without changing authority state.
     *
     * @param  array<string, mixed>  $evaluation
     * @return array<string, mixed>
     */
    public function dryRun(array $evaluation): array
    {
        $structuralChecks = data_get($evaluation, 'checks', []);
        $structurallyAdmissible = is_array($structuralChecks)
            && $structuralChecks !== []
            && ! in_array(false, $structuralChecks, true);
        $signatureValid = data_get($evaluation, 'crypto_context.signature_valid');
        $bindingValid = data_get($evaluation, 'binding_context.controller_binding_valid');
        $causallyRelated = (bool) data_get($evaluation, 'causal_context.remote_extends_local_tip', false);

        return [
            'policy_mode' => self::MODE_OBSERVE_ONLY,
            'evaluation_complete' => true,
            'projection_allowed' => false,
            'projection_effect' => 'none',
            'projection_stage' => $this->projectionStage($structurallyAdmissible, $signatureValid, $bindingValid, $causallyRelated),
            'readiness' => [
                'structurally_admissible' => $structurallyAdmissible,
                'cryptographically_attested' => $signatureValid,
                'claim_coherent' => $bindingValid,
                'causally_related_to_local_tip' => $causallyRelated,
            ],
            'blocking_policy' => [
                'code' => 'observe_only_policy',
                'message' => 'Remote evidence is evaluated but cannot mutate local authority projection in this policy mode.',
            ],
            'decision_effect' => 'report_only',
        ];
    }

    private function projectionStage(bool $structurallyAdmissible, mixed $signatureValid, mixed $bindingValid, bool $causallyRelated): string
    {
        if (! $structurallyAdmissible) {
            return 'not_structurally_admissible';
        }
        if ($signatureValid !== true) {
            return $signatureValid === false ? 'crypto_failed' : 'crypto_unknown';
        }
        if ($bindingValid !== true) {
            return $bindingValid === false ? 'binding_failed' : 'binding_unknown';
        }
        if (! $causallyRelated) {
            return 'causal_relation_unresolved';
        }

        return 'projection_candidate_shadow_only';
    }
}
