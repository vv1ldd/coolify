#!/usr/bin/env php
<?php

declare(strict_types=1);

$repoRoot = dirname(__DIR__);
$defaultSpecPath = dirname($repoRoot).'/simple-l1/docs/specs/sl1-truth-spec.v1.json';
$specPath = getenv('SL1_TRUTH_SPEC_PATH') ?: $defaultSpecPath;

function fail(string $message): never
{
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function pass_check(string $message): void
{
    fwrite(STDOUT, "PASS {$message}\n");
}

function read_json_file(string $path): array
{
    if (! file_exists($path)) {
        fail("file not found: {$path}");
    }

    $payload = json_decode((string) file_get_contents($path), true);
    if (! is_array($payload)) {
        fail("file is not valid JSON: {$path}");
    }

    return $payload;
}

function require_invariant(array $spec, string $id): void
{
    $ids = array_map(fn (array $invariant) => $invariant['id'] ?? null, $spec['invariants'] ?? []);
    if (! in_array($id, $ids, true)) {
        fail("required invariant missing: {$id}");
    }

    pass_check("required invariant present: {$id}");
}

function compile_spec(string $specPath): array
{
    $compilerPath = dirname(dirname($specPath, 2)).'/node/scripts/compile-truth-spec.js';
    if (! file_exists($compilerPath)) {
        fail("SL1 Truth Spec compiler not found at {$compilerPath}");
    }

    $output = shell_exec('node '.escapeshellarg($compilerPath));
    if (! is_string($output) || $output === '') {
        fail('SL1 Truth Spec compiler did not produce output');
    }

    $compiled = json_decode($output, true);
    if (! is_array($compiled) || ($compiled['schema_version'] ?? null) !== 'simple-l1.truth_compiler_output.v1') {
        fail('compiled truth spec output has unexpected schema_version');
    }
    $determinism = $compiled['determinism'] ?? [];
    foreach (['spec_hash', 'compiler_version', 'guard_artifact_hash', 'compiled_output_hash', 'output_ordering'] as $key) {
        if (empty($determinism[$key])) {
            fail("compiled truth spec output missing determinism.{$key}");
        }
    }
    if (($determinism['output_ordering'] ?? null) !== 'stable') {
        fail('compiled truth spec output ordering must be stable');
    }
    if (($determinism['consumers_must_not_regenerate_guards'] ?? null) !== true) {
        fail('consumers must not regenerate guards locally');
    }

    pass_check('compiled SL1 Truth Spec v1 enforcement artifacts');

    return $compiled;
}

function load_release_bundle(string $specPath, array $compiled): array
{
    $compilerPath = dirname(dirname($specPath, 2)).'/node/scripts/compile-truth-spec.js';
    $output = shell_exec('node '.escapeshellarg($compilerPath).' --release-bundle');
    if (! is_string($output) || $output === '') {
        fail('SL1 signed guard artifact bundle did not produce output');
    }

    $bundle = json_decode($output, true);
    if (! is_array($bundle) || ($bundle['schema_version'] ?? null) !== 'simple-l1.signed_guard_artifact.v1') {
        fail('signed guard artifact bundle has unexpected schema_version');
    }
    if (($bundle['artifact_type'] ?? null) !== 'signed_guard_artifact') {
        fail('signed guard artifact bundle has unexpected artifact_type');
    }
    if (($bundle['release_boundary'] ?? null) !== 'SGARP_v0') {
        fail('signed guard artifact bundle must declare SGARP_v0 release boundary');
    }

    $determinism = $compiled['determinism'] ?? [];
    foreach (['spec_hash', 'compiler_version', 'guard_artifact_hash', 'compiled_output_hash'] as $key) {
        if (($bundle[$key] ?? null) !== ($determinism[$key] ?? null)) {
            fail("signed guard artifact bundle {$key} does not match compiled output");
        }
    }

    $signature = $bundle['signature'] ?? [];
    if (empty($signature['algorithm']) || empty($signature['key_id']) || empty($signature['value'])) {
        fail('signed guard artifact bundle must include signature algorithm, key_id, and value');
    }

    $provenance = $bundle['provenance'] ?? [];
    if (($provenance['output_ordering'] ?? null) !== 'stable' || empty($provenance['issuer']) || empty($provenance['issued_at'])) {
        fail('signed guard artifact bundle must include stable provenance');
    }

    pass_check('signed guard artifact release boundary is present and hash-matched');

    return $bundle;
}

function require_runtime_guard(array $compiled, string $from, string $to, string $actor, string $violationCode): void
{
    $guards = $compiled['generated_artifacts']['runtime_guard_definitions']['rejected_transitions'] ?? [];
    foreach ($guards as $guard) {
        if (
            ($guard['from'] ?? null) === $from
            && ($guard['to'] ?? null) === $to
            && ($guard['actor'] ?? null) === $actor
            && ($guard['violation_code'] ?? null) === $violationCode
        ) {
            pass_check("runtime guard rejects {$from}->{$to} by {$actor}");

            return;
        }
    }

    fail("runtime guard missing for {$from}->{$to} by {$actor} ({$violationCode})");
}

function require_contains(string $path, string $needle, string $label): void
{
    $contents = (string) file_get_contents($path);
    if (! str_contains($contents, $needle)) {
        fail("{$label} missing marker: {$needle}");
    }

    pass_check("{$label} contains marker: {$needle}");
}

$spec = read_json_file($specPath);
if (($spec['schema_version'] ?? null) !== 'simple-l1.truth_spec.v1') {
    fail('SL1 Truth Spec schema_version is not simple-l1.truth_spec.v1');
}
pass_check('loaded SL1 Truth Spec v1');
$compiled = compile_spec($specPath);
load_release_bundle($specPath, $compiled);

foreach ([
    'BRIDGE_NO_ADMISSION',
    'UI_NO_PEER_MUTATION',
    'VISIBLE_NODE_IS_PROJECTION',
    'TLS_NO_CONVERGE_GATE',
] as $invariantId) {
    require_invariant($spec, $invariantId);
}

require_runtime_guard($compiled, 'ui_projection_layer', 'admitted_peer', 'ui_projection_layer', 'UI_AUTHORITY_VIOLATION');
require_runtime_guard($compiled, 'tls_state', 'converge', 'postflight_observer', 'TLS_CONVERGE_GATE_VIOLATION');

$serverIndex = "{$repoRoot}/app/Livewire/Server/Index.php";
$serverView = "{$repoRoot}/resources/views/livewire/server/index.blade.php";
$peerRegistry = "{$repoRoot}/app/Services/Sl1PeerRegistryService.php";
$upgradeScript = "{$repoRoot}/scripts/upgrade-sovereign.sh";

require_contains($serverIndex, "'admission_owner' => 'host'", 'UI projection model');
require_contains($serverIndex, "'visibility_owner' => 'ui_projection'", 'UI projection model');
require_contains($serverIndex, "'discovery_candidates' =>", 'UI projection model');
require_contains($serverIndex, 'already_admitted', 'UI projection model');

require_contains($peerRegistry, 'requireHostAdmissionActor', 'peer admission service');
require_contains($peerRegistry, 'ADMISSION_AUTHORITY_VIOLATION', 'peer admission service');

require_contains($serverView, 'Admitted SL1 Peers', 'mesh view');
require_contains($serverView, 'Bridge-Visible Candidates', 'mesh view');
require_contains($serverView, 'Evidence Only', 'mesh view');
require_contains($serverView, 'NOT ADMITTED', 'mesh view');

require_contains($upgradeScript, 'observe_panel_tls', 'postflight TLS');
require_contains($upgradeScript, 'panel_tls_state=', 'postflight TLS');
require_contains($upgradeScript, 'TLS is postflight-only; converge remains complete', 'postflight TLS');
require_contains($upgradeScript, 'return 0', 'postflight TLS');

pass_check('coolify projection and postflight states are derivable from SL1 Truth Spec v1');
