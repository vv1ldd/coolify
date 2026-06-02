<?php

use App\Http\Middleware\EdgeProtectionChallenge;
use App\Services\EdgeProtection\EdgeProtectionService;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config()->set('sovereign.edge_protection.enabled', true);
    config()->set('sovereign.edge_protection.mode', 'challenge');
    config()->set('sovereign.edge_protection.cookie_name', 'coolify_edge_proof');
    config()->set('sovereign.edge_protection.proof_ttl_minutes', 10);
    config()->set('sovereign.edge_protection.challenge_ttl_seconds', 120);
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

test('valid challenge sets proof cookie and redirects to relative return_to', function () {
    $challenge = $this
        ->withHeader('User-Agent', 'Mozilla/5.0')
        ->get('/__edge/challenge?return_to='.urlencode('/edge-protected-test?from=challenge'));

    $challenge->assertOk()->assertSee('Sovereign Edge Check');

    $verify = $this
        ->withHeader('User-Agent', 'Mozilla/5.0')
        ->get('/__edge/challenge/verify?token='.urlencode(edgeProtectionChallengeTokenFrom($challenge)));

    $verify
        ->assertRedirect('/edge-protected-test?from=challenge')
        ->assertCookie('coolify_edge_proof');
});

test('external return_to is sanitized before challenge verification', function () {
    $challenge = $this
        ->withHeader('User-Agent', 'Mozilla/5.0')
        ->get('/__edge/challenge?return_to='.urlencode('https://evil.example/pwn'));

    $challenge
        ->assertOk()
        ->assertSee('name="return_to" value="/"', false);

    $this
        ->withHeader('User-Agent', 'Mozilla/5.0')
        ->get('/__edge/challenge/verify?token='.urlencode(edgeProtectionChallengeTokenFrom($challenge)))
        ->assertRedirect('/');
});

test('middleware allows requests with a valid proof cookie', function () {
    $proofValue = app(EdgeProtectionService::class)->createProofValue(edgeProtectionRequest('/__edge/challenge/verify'));
    $request = edgeProtectionRequest('/edge-protected-test', ['coolify_edge_proof' => $proofValue]);

    $response = app(EdgeProtectionChallenge::class)
        ->handle($request, fn () => response('trusted application'));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('trusted application');
});

test('middleware redirects missing proof to challenge in challenge mode', function () {
    $response = app(EdgeProtectionChallenge::class)
        ->handle(edgeProtectionRequest('/edge-protected-test?foo=bar'), fn () => response('trusted application'));

    expect($response->getStatusCode())->toBe(302);

    expect($response->headers->get('Location'))
        ->toContain('/__edge/challenge')
        ->toContain('return_to=%2Fedge-protected-test%3Ffoo%3Dbar');
});

test('hostile user agents and probe paths are silently blocked when configured', function () {
    $badUserAgent = app(EdgeProtectionChallenge::class)
        ->handle(edgeProtectionRequest('/edge-protected-test', userAgent: 'sqlmap'), fn () => response('trusted application'));

    $probePath = app(EdgeProtectionChallenge::class)
        ->handle(edgeProtectionRequest('/.env'), fn () => response('probe reached'));

    expect($badUserAgent->getStatusCode())->toBe(404)
        ->and($badUserAgent->getContent())->toBe('')
        ->and($probePath->getStatusCode())->toBe(404)
        ->and($probePath->getContent())->toBe('');
});

function edgeProtectionChallengeTokenFrom(TestResponse $response): string
{
    preg_match('/name="token" value="([^"]+)"/', $response->getContent(), $matches);

    expect($matches[1] ?? null)->not->toBeNull();

    return html_entity_decode($matches[1], ENT_QUOTES);
}

function edgeProtectionRequest(string $uri, array $cookies = [], string $userAgent = 'Mozilla/5.0'): Request
{
    return Request::create($uri, 'GET', [], $cookies, [], [
        'HTTP_HOST' => 'localhost',
        'HTTP_USER_AGENT' => $userAgent,
    ]);
}
