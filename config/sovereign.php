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
        'relying_party' => [
            'name' => env('SOVEREIGN_RP_NAME', 'Meanly Sovereign Cloud'),
            'id' => env('SOVEREIGN_RP_ID', 'localhost'),
        ],
        'challenge_timeout' => env('SOVEREIGN_CHALLENGE_TIMEOUT', 60),
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
];
