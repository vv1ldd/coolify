<?php

it('builds traefik catchall config with branded unavailable middleware', function () {
    $config = traefikCatchallDynamicConfig();

    expect($config['http']['routers']['catchall']['middlewares'])
        ->toContain('coolify-edge-unavailable')
        ->and($config['http']['middlewares']['coolify-edge-unavailable']['errors']['service'])
        ->toBe('coolify-edge-status')
        ->and($config['http']['services']['coolify-edge-status']['loadBalancer']['servers'][0]['url'])
        ->toBe('http://coolify-edge-status:80');
});

it('keeps redirect middleware ahead of unavailable page middleware', function () {
    $config = traefikCatchallDynamicConfig('https://example.com');

    expect($config['http']['routers']['catchall']['middlewares'])
        ->toBe(['redirect-regexp', 'coolify-edge-unavailable']);
});
