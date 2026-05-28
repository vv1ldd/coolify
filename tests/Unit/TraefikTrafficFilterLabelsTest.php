<?php

use Tests\TestCase;

uses(TestCase::class);

test('traefik labels include default edge traffic filtering middlewares', function () {
    config()->set('sovereign.traffic_filter', [
        'enabled' => true,
        'rate_limit_average' => 50,
        'rate_limit_burst' => 100,
        'in_flight_request_limit' => 25,
        'security_headers_enabled' => true,
        'user_agent_filter_enabled' => false,
        'block_empty_user_agent' => true,
        'suspicious_user_agent_patterns' => [],
        'probe_path_filter_enabled' => false,
        'suspicious_path_prefixes' => [],
        'allowed_source_ranges' => ['10.0.0.0/8', 'invalid-range'],
    ]);

    $labels = fqdnLabelsForTraefik(
        uuid: 'app123',
        domains: collect(['https://example.com']),
        is_gzip_enabled: false,
    );

    expect($labels)->toContain('traefik.http.middlewares.coolify-edge-app123-ipallowlist.ipallowlist.sourcerange=10.0.0.0/8')
        ->and($labels)->toContain('traefik.http.middlewares.coolify-edge-app123-ratelimit.ratelimit.average=50')
        ->and($labels)->toContain('traefik.http.middlewares.coolify-edge-app123-ratelimit.ratelimit.burst=100')
        ->and($labels)->toContain('traefik.http.middlewares.coolify-edge-app123-inflight.inflightreq.amount=25')
        ->and($labels)->toContain('traefik.http.middlewares.coolify-edge-app123-headers.headers.contenttypenosniff=true')
        ->and($labels)->toContain('traefik.http.routers.https-0-app123.middlewares=coolify-edge-app123-ipallowlist,coolify-edge-app123-ratelimit,coolify-edge-app123-inflight,coolify-edge-app123-headers');
});

test('traefik traffic filter labels can be disabled', function () {
    config()->set('sovereign.traffic_filter.enabled', false);

    $labels = fqdnLabelsForTraefik(
        uuid: 'app123',
        domains: collect(['https://example.com']),
        is_gzip_enabled: false,
    );

    expect(collect($labels)->filter(fn (string $label) => str_contains($label, 'coolify-edge-app123')))
        ->toBeEmpty();
});

test('traefik labels block empty and suspicious user agents before application routers', function () {
    config()->set('sovereign.traffic_filter', [
        'enabled' => true,
        'rate_limit_average' => 0,
        'rate_limit_burst' => 0,
        'in_flight_request_limit' => 0,
        'security_headers_enabled' => false,
        'user_agent_filter_enabled' => true,
        'block_empty_user_agent' => true,
        'suspicious_user_agent_patterns' => ['curl', 'python-requests', 'sqlmap'],
        'probe_path_filter_enabled' => false,
        'suspicious_path_prefixes' => [],
        'allowed_source_ranges' => [],
    ]);

    $labels = fqdnLabelsForTraefik(
        uuid: 'app123',
        domains: collect(['https://example.com/api']),
        is_gzip_enabled: false,
    );

    expect($labels)->toContain('traefik.http.routers.coolify-edge-0-app123-empty-ua-http.rule=Host(`example.com`) && PathPrefix(`/api`) && !HeaderRegexp(`User-Agent`, `.+`)')
        ->and($labels)->toContain('traefik.http.routers.coolify-edge-0-app123-empty-ua-http.priority=100000')
        ->and($labels)->toContain('traefik.http.routers.coolify-edge-0-app123-empty-ua-http.service=noop@internal')
        ->and($labels)->toContain('traefik.http.routers.coolify-edge-0-app123-bad-ua-https.rule=Host(`example.com`) && PathPrefix(`/api`) && HeaderRegexp(`User-Agent`, `(?i)(curl|python\\-requests|sqlmap)`)')
        ->and($labels)->toContain('traefik.http.routers.coolify-edge-0-app123-bad-ua-https.tls=true');
});

test('user agent filtering can be disabled independently from rate limits', function () {
    config()->set('sovereign.traffic_filter', [
        'enabled' => true,
        'rate_limit_average' => 50,
        'rate_limit_burst' => 100,
        'in_flight_request_limit' => 0,
        'security_headers_enabled' => false,
        'user_agent_filter_enabled' => false,
        'block_empty_user_agent' => true,
        'suspicious_user_agent_patterns' => ['curl'],
        'probe_path_filter_enabled' => false,
        'suspicious_path_prefixes' => [],
        'allowed_source_ranges' => [],
    ]);

    $labels = fqdnLabelsForTraefik(
        uuid: 'app123',
        domains: collect(['https://example.com']),
        is_gzip_enabled: false,
    );

    expect(collect($labels)->filter(fn (string $label) => str_contains($label, 'bad-ua') || str_contains($label, 'empty-ua')))
        ->toBeEmpty()
        ->and($labels)->toContain('traefik.http.middlewares.coolify-edge-app123-ratelimit.ratelimit.average=50');
});

test('empty user agents are not blocked by default', function () {
    config()->set('sovereign.traffic_filter', [
        'enabled' => true,
        'rate_limit_average' => 0,
        'rate_limit_burst' => 0,
        'in_flight_request_limit' => 0,
        'security_headers_enabled' => false,
        'user_agent_filter_enabled' => true,
        'block_empty_user_agent' => false,
        'suspicious_user_agent_patterns' => ['curl'],
        'probe_path_filter_enabled' => false,
        'suspicious_path_prefixes' => [],
        'allowed_source_ranges' => [],
    ]);

    $labels = fqdnLabelsForTraefik(
        uuid: 'app123',
        domains: collect(['https://example.com']),
        is_gzip_enabled: false,
    );

    expect(collect($labels)->filter(fn (string $label) => str_contains($label, 'empty-ua')))
        ->toBeEmpty()
        ->and(collect($labels)->filter(fn (string $label) => str_contains($label, 'bad-ua')))
        ->not->toBeEmpty();
});

test('traefik labels block common capability discovery probes before application routers', function () {
    config()->set('sovereign.traffic_filter', [
        'enabled' => true,
        'rate_limit_average' => 0,
        'rate_limit_burst' => 0,
        'in_flight_request_limit' => 0,
        'security_headers_enabled' => false,
        'user_agent_filter_enabled' => false,
        'block_empty_user_agent' => false,
        'suspicious_user_agent_patterns' => [],
        'probe_path_filter_enabled' => true,
        'suspicious_path_prefixes' => ['/.env', '/wp-admin', 'phpmyadmin', 'bad;path'],
        'allowed_source_ranges' => [],
    ]);

    $labels = fqdnLabelsForTraefik(
        uuid: 'app123',
        domains: collect(['https://example.com']),
        is_gzip_enabled: false,
    );

    expect($labels)->toContain('traefik.http.routers.coolify-edge-0-app123-probe-http.rule=Host(`example.com`) && (PathPrefix(`/.env`) || PathPrefix(`/wp-admin`) || PathPrefix(`/phpmyadmin`))')
        ->and($labels)->toContain('traefik.http.routers.coolify-edge-0-app123-probe-http.priority=100000')
        ->and($labels)->toContain('traefik.http.routers.coolify-edge-0-app123-probe-http.service=noop@internal')
        ->and($labels)->toContain('traefik.http.routers.coolify-edge-0-app123-probe-https.tls=true');
});

test('traffic filter source ranges accept cidr and reject invalid ranges', function () {
    $ranges = normalizeTrafficFilterSourceRanges([
        '192.168.1.1',
        '10.0.0.0/8',
        '2001:db8::/32',
        '10.0.0.0/99',
        'not-a-range',
    ]);

    expect($ranges->all())->toBe([
        '192.168.1.1',
        '10.0.0.0/8',
        '2001:db8::/32',
    ]);
});

test('traffic filter path prefixes are normalized and unsafe paths are rejected', function () {
    $paths = normalizeTrafficFilterPathPrefixes([
        '.env',
        '/wp-admin',
        '/docker-compose.yml',
        'bad;path',
    ]);

    expect($paths->all())->toBe([
        '/.env',
        '/wp-admin',
        '/docker-compose.yml',
    ]);
});
