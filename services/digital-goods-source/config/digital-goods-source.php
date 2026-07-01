<?php

return [
    'service' => env('DIGITAL_GOODS_SOURCE_SERVICE_NAME', env('WILDFLOW_KERNEL_SERVICE_NAME', 'digital-goods-source')),
    'runtime_version' => env('DIGITAL_GOODS_SOURCE_RUNTIME_VERSION', env('WILDFLOW_KERNEL_RUNTIME_VERSION', '1.0.0')),
    'kernel_protocol_version' => env('DIGITAL_GOODS_SOURCE_KERNEL_PROTOCOL_VERSION', env('WILDFLOW_KERNEL_PROTOCOL_VERSION', 'v1')),
    'provider_contract_version' => env('DIGITAL_GOODS_SOURCE_PROVIDER_CONTRACT_VERSION', env('WILDFLOW_PROVIDER_CONTRACT_VERSION', 'v1')),
    'platform_token' => env('DIGITAL_GOODS_SOURCE_PLATFORM_TOKEN', env('WILDFLOW_KERNEL_PLATFORM_TOKEN')),
    'financial_secret' => env('DIGITAL_GOODS_SOURCE_FINANCIAL_SECRET', env('WILDFLOW_KERNEL_FINANCIAL_SECRET')),
    'signature_tolerance_seconds' => (int) env('DIGITAL_GOODS_SOURCE_SIGNATURE_TOLERANCE', env('WILDFLOW_KERNEL_SIGNATURE_TOLERANCE', 300)),
    'catalog_source_url' => env('CATALOG_SOURCE_URL'),
    'catalog_source_auth_token' => env('CATALOG_SOURCE_AUTH_TOKEN', env('DIGITAL_GOODS_SOURCE_PLATFORM_TOKEN')),
    'edge_mode' => (bool) env('DGS_EDGE_MODE', false),
    'providers' => [
        'ezpin' => [
            'base_url' => env('EZPIN_BASE_URL'),
            'client_id' => env('EZPIN_CLIENT_ID'),
            'secret_key' => env('EZPIN_SECRET_KEY'),
        ],
        'fazer' => [
            'base_url' => env('FAZER_BASE_URL'),
            'api_key' => env('FAZER_API_KEY'),
        ],
    ],
];
