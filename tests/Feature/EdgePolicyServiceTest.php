<?php

use App\Models\EdgePolicy;
use App\Models\Team;
use App\Services\EdgeProtection\EdgeRequestClassifier;
use App\Services\EdgeProtection\EdgePolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('sovereign.edge_protection.enabled', true);
    config()->set('sovereign.edge_protection.mode', 'off');
    config()->set('sovereign.edge_protection.cookie_name', 'coolify_edge_proof');
    config()->set('sovereign.edge_protection.block_status', 404);
    config()->set('sovereign.edge_protection.block_bad_user_agents', true);
    config()->set('sovereign.edge_protection.block_probe_paths', true);
    config()->set('sovereign.edge_protection.log_decisions', false);

    config()->set('sovereign.traffic_filter.user_agent_filter_enabled', true);
    config()->set('sovereign.traffic_filter.block_empty_user_agent', false);
    config()->set('sovereign.traffic_filter.suspicious_user_agent_patterns', ['sqlmap']);
    config()->set('sovereign.traffic_filter.probe_path_filter_enabled', true);
    config()->set('sovereign.traffic_filter.suspicious_path_prefixes', ['/.env']);
});

test('edge policy service creates and resolves exact domain policy', function () {
    $team = Team::factory()->create();

    $policy = app(EdgePolicyService::class)->createForTeam($team->id, [
        'name' => 'Checkout strict policy',
        'mode' => EdgePolicy::MODE_STRICT,
        'scope_type' => EdgePolicy::SCOPE_DOMAIN,
        'scope_value' => 'App.Example.COM',
        'challenge_enabled' => true,
        'silent_drop_enabled' => false,
        'rate_limit_average' => 50,
        'rate_limit_burst' => 100,
        'in_flight_limit' => 25,
    ]);

    $resolved = app(EdgePolicyService::class)->resolveByDomain($team->id, 'app.example.com');

    expect($policy->uuid)->not()->toBeEmpty()
        ->and($resolved->uuid)->toBe($policy->uuid)
        ->and($resolved->mode)->toBe(EdgePolicy::MODE_STRICT)
        ->and($resolved->scopeValue)->toBe('app.example.com')
        ->and($resolved->rateLimitAverage)->toBe(50);
});

test('under attack policy challenges requests without proof', function () {
    $team = Team::factory()->create();
    app(EdgePolicyService::class)->createForTeam($team->id, [
        'name' => 'Attack mode',
        'mode' => EdgePolicy::MODE_UNDER_ATTACK,
        'scope_type' => EdgePolicy::SCOPE_DOMAIN,
        'scope_value' => 'attack.example.com',
    ]);

    $decision = app(EdgeRequestClassifier::class)
        ->classify(edgePolicyRequest('/checkout', host: 'attack.example.com'), ['team_id' => $team->id]);

    expect($decision->challenges())->toBeTrue()
        ->and($decision->reason)->toBe('missing proof');
});

test('off policy allows requests without proof or silent drop', function () {
    $team = Team::factory()->create();
    app(EdgePolicyService::class)->createForTeam($team->id, [
        'name' => 'Disabled edge policy',
        'mode' => EdgePolicy::MODE_OFF,
        'scope_type' => EdgePolicy::SCOPE_DOMAIN,
        'scope_value' => 'off.example.com',
        'silent_drop_enabled' => true,
    ]);

    $decision = app(EdgeRequestClassifier::class)
        ->classify(edgePolicyRequest('/.env', host: 'off.example.com', userAgent: 'sqlmap'), ['team_id' => $team->id]);

    expect($decision->allows())->toBeTrue()
        ->and($decision->reason)->toBe('edge policy off');
});

test('hostile request is silently blocked when policy enables silent drop', function () {
    $team = Team::factory()->create();
    app(EdgePolicyService::class)->createForTeam($team->id, [
        'name' => 'Silent drop policy',
        'mode' => EdgePolicy::MODE_NORMAL,
        'scope_type' => EdgePolicy::SCOPE_DOMAIN,
        'scope_value' => 'drop.example.com',
        'silent_drop_enabled' => true,
    ]);

    $decision = app(EdgeRequestClassifier::class)
        ->classify(edgePolicyRequest('/checkout', host: 'drop.example.com', userAgent: 'sqlmap'), ['team_id' => $team->id]);

    expect($decision->blocks())->toBeTrue()
        ->and($decision->reason)->toBe('bad user agent');
});

test('domain policies are not resolved across teams without explicit team context', function () {
    $team = Team::factory()->create();
    app(EdgePolicyService::class)->createForTeam($team->id, [
        'name' => 'Tenant scoped policy',
        'mode' => EdgePolicy::MODE_UNDER_ATTACK,
        'scope_type' => EdgePolicy::SCOPE_DOMAIN,
        'scope_value' => 'tenant.example.com',
    ]);

    $resolved = app(EdgePolicyService::class)->resolveByDomain(null, 'tenant.example.com');

    expect($resolved->source)->toBe('config')
        ->and($resolved->uuid)->toBeNull();
});

test('edge policy cannot be mutated outside service', function () {
    $team = Team::factory()->create();

    expect(fn () => EdgePolicy::create([
        'team_id' => $team->id,
        'name' => 'Direct write',
        'mode' => EdgePolicy::MODE_NORMAL,
        'scope_type' => EdgePolicy::SCOPE_DOMAIN,
        'scope_value' => 'direct.example.com',
    ]))->toThrow(LogicException::class, 'EdgePolicy can only be mutated through EdgePolicyService.');
});

function edgePolicyRequest(string $uri, string $host, string $userAgent = 'Mozilla/5.0'): Request
{
    return Request::create($uri, 'GET', [], [], [], [
        'HTTP_HOST' => $host,
        'HTTP_USER_AGENT' => $userAgent,
    ]);
}
