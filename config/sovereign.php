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
        'client_secret' => env('SL1_CONNECT_SECRET'),
        'callback_path' => env('SL1_CONNECT_CALLBACK_PATH', '/auth/sl1/callback'),
        'timeout' => (int) env('SL1_CONNECT_TIMEOUT', 10),
        'embedded' => [
            'enabled' => env('SL1_EMBEDDED_RUNTIME_ENABLED', true),
            'issuer_path' => env('SL1_EMBEDDED_ISSUER_PATH', '/sl1'),
            'issuer_url' => env('SIMPLE_L1_ISSUER_URL'),
            'storage_role' => env('SIMPLE_L1_STORAGE_ROLE', 'cache'),
            'identity_protocol_version' => env('SIMPLE_L1_IDENTITY_PROTOCOL_VERSION', 'capsule-v0'),
            'identity_capsules_enabled' => env('SIMPLE_L1_IDENTITY_CAPSULES_ENABLED', true),
            'evidence_resolvers' => array_values(array_filter(array_map(
                'trim',
                explode(',', env('SIMPLE_L1_EVIDENCE_RESOLVERS', 'local-cache,client-capsule,peer,signed-export'))
            ))),
            'state_resolvers' => array_values(array_filter(array_map(
                'trim',
                explode(',', env('SIMPLE_L1_STATE_RESOLVERS', 'local-cache,peer,anchor,quorum,signed-export'))
            ))),
            'default_assurance_level' => env('SIMPLE_L1_DEFAULT_ASSURANCE_LEVEL', 'AL1'),
        ],
    ],

    'pending_intents' => [
        'ttl_minutes' => (int) env('SOVEREIGN_PENDING_INTENT_TTL_MINUTES', 30),
    ],

    'digital_goods_source' => [
        'enabled' => env('DIGITAL_GOODS_SOURCE_ENABLED', env('WILDFLOW_KERNEL_ENABLED', true)),
        'url' => env('DIGITAL_GOODS_SOURCE_URL', env('WILDFLOW_KERNEL_URL', 'http://digital-goods-source:8080')),
        'status_urls' => array_values(array_filter(array_map(
            'trim',
            explode(',', env('DIGITAL_GOODS_SOURCE_STATUS_URLS', env('WILDFLOW_KERNEL_STATUS_URLS', 'http://digital-goods-source:8080,http://127.0.0.1:8091')))
        ))),
        'image' => env('DIGITAL_GOODS_SOURCE_IMAGE', env('WILDFLOW_KERNEL_IMAGE', 'ghcr.io/vv1ldd/digital-goods-source:latest')),
        'port' => (int) env('DIGITAL_GOODS_SOURCE_PORT', env('WILDFLOW_KERNEL_PORT', 8091)),
        'runtime_version' => env('DIGITAL_GOODS_SOURCE_RUNTIME_VERSION', env('WILDFLOW_KERNEL_RUNTIME_VERSION', '1.0.0')),
        'kernel_protocol_version' => env('DIGITAL_GOODS_SOURCE_KERNEL_PROTOCOL_VERSION', env('WILDFLOW_KERNEL_PROTOCOL_VERSION', 'v1')),
        'provider_contract_version' => env('DIGITAL_GOODS_SOURCE_PROVIDER_CONTRACT_VERSION', env('WILDFLOW_PROVIDER_CONTRACT_VERSION', 'v1')),
        'timeout' => (int) env('DIGITAL_GOODS_SOURCE_TIMEOUT', env('WILDFLOW_KERNEL_TIMEOUT', 10)),
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

    /*
    |--------------------------------------------------------------------------
    | Incident Protection Levels
    |--------------------------------------------------------------------------
    |
    | Assessment and action planning only. EdgePolicy owns L7 mutations, DNS
    | Steering owns DNS changes, and provider adapters own host actions. Provider
    | actions remain dry-run unless a future approved production adapter opts in.
    |
    */
    'incident_protection' => [
        'enabled' => env('SOVEREIGN_INCIDENT_PROTECTION_ENABLED', true),
        'runbook' => env('SOVEREIGN_INCIDENT_PROTECTION_RUNBOOK', 'sovereign-protection-v1'),
        'dry_run_provider_actions' => env('SOVEREIGN_INCIDENT_PROVIDER_DRY_RUN', true),
        'require_provider_action_approval' => true,
        'levels' => [
            'normal' => [
                'min_score' => 0,
                'actions' => [],
            ],
            'elevated' => [
                'min_score' => 25,
                'actions' => ['notify'],
            ],
            'high' => [
                'min_score' => 50,
                'actions' => ['tighten_rate_limit', 'notify'],
                'rate_limit_average' => 80,
                'rate_limit_burst' => 160,
            ],
            'critical' => [
                'min_score' => 75,
                'actions' => ['set_edge_policy_mode', 'enable_challenge', 'tighten_rate_limit', 'notify'],
                'edge_policy_mode' => 'under_attack',
                'rate_limit_average' => 40,
                'rate_limit_burst' => 80,
            ],
            'emergency' => [
                'min_score' => 90,
                'actions' => [
                    'set_edge_policy_mode',
                    'enable_challenge',
                    'tighten_rate_limit',
                    'dns_failover_plan',
                    'notify',
                    'provider_isolate_server',
                    'provider_poweroff_server',
                ],
                'edge_policy_mode' => 'under_attack',
                'rate_limit_average' => 20,
                'rate_limit_burst' => 40,
            ],
        ],
        'provider_action_adapters' => [
            'selectel_vds' => 'implemented',
            'hostinger_vps' => 'implemented',
            'hetzner' => 'planned_adapter_required',
            'cloud_provider' => 'planned_adapter_required',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Provider Control
    |--------------------------------------------------------------------------
    |
    | Base URLs and transport settings for host provider control adapters.
    | Provider secrets are stored only in encrypted CloudProviderToken records.
    |
    */
    'provider_control' => [
        'timeout' => (int) env('SOVEREIGN_PROVIDER_CONTROL_TIMEOUT', 10),
        'connect_timeout' => (int) env('SOVEREIGN_PROVIDER_CONTROL_CONNECT_TIMEOUT', 5),
        'providers' => [
            'selectel_vds' => [
                'base_url' => env('SELECTEL_VDS_API_BASE_URL', 'https://api.vscale.io/v1'),
            ],
            'hostinger_vps' => [
                'base_url' => env('HOSTINGER_VPS_API_BASE_URL', 'https://developers.hostinger.com'),
                'isolation_firewall_id' => env('HOSTINGER_VPS_ISOLATION_FIREWALL_ID'),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Edge Protection Challenge Layer
    |--------------------------------------------------------------------------
    |
    | Stateful trust mediation that can be attached to application routes later.
    | Traefik labels still handle hard stateless filtering; this layer issues a
    | short-lived browser proof cookie for challenge/under-attack mode.
    |
    */
    'edge_protection' => [
        'enabled' => env('SOVEREIGN_EDGE_PROTECTION_ENABLED', true),
        'mode' => env('SOVEREIGN_EDGE_PROTECTION_MODE', 'off'), // off, challenge, under_attack
        'cookie_name' => env('SOVEREIGN_EDGE_PROTECTION_COOKIE', 'coolify_edge_proof'),
        'proof_ttl_minutes' => (int) env('SOVEREIGN_EDGE_PROTECTION_PROOF_TTL_MINUTES', 10),
        'challenge_ttl_seconds' => (int) env('SOVEREIGN_EDGE_PROTECTION_CHALLENGE_TTL_SECONDS', 120),
        'challenge_path' => env('SOVEREIGN_EDGE_PROTECTION_CHALLENGE_PATH', '/__edge/challenge'),
        'verify_path' => env('SOVEREIGN_EDGE_PROTECTION_VERIFY_PATH', '/__edge/challenge/verify'),
        'block_status' => (int) env('SOVEREIGN_EDGE_PROTECTION_BLOCK_STATUS', 404),
        'block_bad_user_agents' => env('SOVEREIGN_EDGE_PROTECTION_BLOCK_BAD_UA', true),
        'block_probe_paths' => env('SOVEREIGN_EDGE_PROTECTION_BLOCK_PROBES', true),
        'log_decisions' => env('SOVEREIGN_EDGE_PROTECTION_LOG_DECISIONS', true),
    ],
];
