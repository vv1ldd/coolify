<?php

use App\Models\Server;
use App\Services\AgentContainerService;

test('it rejects unsafe container names before running remote commands', function () {
    $service = new AgentContainerService;

    expect(fn () => $service->logs(new Server, 'bad;name'))
        ->toThrow(InvalidArgumentException::class, 'Invalid container name.');
});

test('it rejects shell-unsafe exec commands before running remote commands', function () {
    $service = new AgentContainerService;

    expect(fn () => $service->exec(new Server, 'app', 'echo ok; rm -rf /'))
        ->toThrow(InvalidArgumentException::class, 'Command contains shell-unsafe characters.');
});
