<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Sovereign L1 Blockchain Settings
    |--------------------------------------------------------------------------
    |
    | Here you specify your decentralized Simple-L1 Fabric node URL and the
    | cryptographic keys used for server handshakes and transaction signing.
    |
    */
    'l1_node_url' => env('SOVEREIGN_L1_NODE_URL', 'http://127.0.0.1:8545'),

    'l1_contract_address' => env('SOVEREIGN_L1_CONTRACT_ADDRESS', '0x0000000000000000000000000000000000000000'),

    /*
    |--------------------------------------------------------------------------
    | WebAuthn / Passkeys Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for biometric login and cryptographic user verification.
    |
    */
    'passkeys' => [
        'development_stub_enabled' => env('SOVEREIGN_PASSKEYS_DEVELOPMENT_STUB_ENABLED', false),
        'relying_party' => [
            'name' => env('SOVEREIGN_RP_NAME', 'Meanly Sovereign Cloud'),
            'id' => env('SOVEREIGN_RP_ID', 'localhost'),
        ],
        'challenge_timeout' => env('SOVEREIGN_CHALLENGE_TIMEOUT', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | SL1 Connect Identity Provider
    |--------------------------------------------------------------------------
    |
    | Coolify authenticates users through SL1 Connect. The local Laravel user is
    | only an application projection of the verified SL1 entity address.
    |
    */
    'sl1_connect' => [
        'issuer' => env('SL1_CONNECT_ISSUER', 'https://simplel1.online'),
        'client_id' => env('SL1_CONNECT_CLIENT_ID', 'coolify.sovereign'),
        'client_name' => env('SL1_CONNECT_CLIENT_NAME', 'Sovereign Coolify'),
        'callback_path' => env('SL1_CONNECT_CALLBACK_PATH', '/auth/sl1/callback'),
        'timeout' => (int) env('SL1_CONNECT_TIMEOUT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | B2B Consortium Clearing & Billing
    |--------------------------------------------------------------------------
    |
    | Configuration for connecting container lifecycles to partner deposits.
    |
    */
    'clearing' => [
        'api_url' => env('SOVEREIGN_CLEARING_API_URL', 'https://meanly.test/api/v1/clearing'),
        'api_token' => env('SOVEREIGN_CLEARING_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Edge Traffic Filtering
    |--------------------------------------------------------------------------
    |
    | First local Cloudflare-like guardrail for environments where traffic
    | cannot be proxied through Cloudflare. These settings are enforced through
    | Traefik middlewares on public application routers.
    |
    */
    'traffic_filter' => [
        'enabled' => env('SOVEREIGN_TRAFFIC_FILTER_ENABLED', true),
        'rate_limit_average' => (int) env('SOVEREIGN_TRAFFIC_FILTER_RATE_AVERAGE', 120),
        'rate_limit_burst' => (int) env('SOVEREIGN_TRAFFIC_FILTER_RATE_BURST', 240),
        'in_flight_request_limit' => (int) env('SOVEREIGN_TRAFFIC_FILTER_IN_FLIGHT_LIMIT', 100),
        'security_headers_enabled' => env('SOVEREIGN_TRAFFIC_FILTER_SECURITY_HEADERS', true),
        'user_agent_filter_enabled' => env('SOVEREIGN_TRAFFIC_FILTER_USER_AGENT_ENABLED', true),
        'block_empty_user_agent' => env('SOVEREIGN_TRAFFIC_FILTER_BLOCK_EMPTY_USER_AGENT', false),
        'suspicious_user_agent_patterns' => [
            'acunetix',
            'ahrefsbot',
            'attackbot',
            'babbar',
            'blackwidow',
            'bytespider',
            'censysinspect',
            'curl',
            'dirbuster',
            'dotbot',
            'gobuster',
            'masscan',
            'nikto',
            'nmap',
            'openvas',
            'python-requests',
            'scrapy',
            'semrushbot',
            'sqlmap',
            'wget',
            'wpscan',
            ...array_values(array_filter(array_map(
                'trim',
                explode(',', env('SOVEREIGN_TRAFFIC_FILTER_SUSPICIOUS_USER_AGENTS', ''))
            ))),
        ],
        'probe_path_filter_enabled' => env('SOVEREIGN_TRAFFIC_FILTER_PROBE_PATH_ENABLED', true),
        'suspicious_path_prefixes' => [
            '/.aws',
            '/.docker',
            '/.env',
            '/.git',
            '/.hg',
            '/.svn',
            '/adminer',
            '/api/v1/admin',
            '/backup',
            '/config',
            '/debug',
            '/docker-compose',
            '/phpinfo',
            '/phpmyadmin',
            '/pma',
            '/server-status',
            '/vendor/phpunit',
            '/wp-admin',
            '/wp-login.php',
            '/xmlrpc.php',
            ...array_values(array_filter(array_map(
                'trim',
                explode(',', env('SOVEREIGN_TRAFFIC_FILTER_SUSPICIOUS_PATH_PREFIXES', ''))
            ))),
        ],
        'allowed_source_ranges' => array_values(array_filter(array_map(
            'trim',
            explode(',', env('SOVEREIGN_TRAFFIC_FILTER_ALLOWED_SOURCE_RANGES', ''))
        ))),
    ],
];
