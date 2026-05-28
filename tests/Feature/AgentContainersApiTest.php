<?php

use App\Models\InfraAuthorization;
use App\Models\InfraLedger;
use App\Models\InstanceSettings;
use App\Models\PolicyDecision;
use App\Models\Server;
use App\Models\ServerSetting;
use App\Models\Team;
use App\Models\User;
use App\Services\AgentContainerService;
use App\Services\InfraAuthorizationService;
use App\Services\PolicyEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::create(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'ip' => '1.2.3.4',
    ]);
});

function agentApiHeaders(User $user, Team $team, array $abilities = ['read']): array
{
    session(['currentTeam' => $team]);
    $token = $user->createToken('agent-test-token', $abilities);

    return [
        'Authorization' => 'Bearer '.$token->plainTextToken,
        'Content-Type' => 'application/json',
    ];
}

function policyDecisionForContainerExec(User $user, Server $server, string $container = 'app', string $command = 'php -v', int $timeout = 15): PolicyDecision
{
    return PolicyDecision::create(app(PolicyEngine::class)->evaluateContainerExec(
        user: $user,
        server: $server,
        container: $container,
        command: $command,
        timeout: $timeout,
        riskContext: ['risk_level' => 'LOW'],
    ));
}

test('read token can query agent containers on an owned server', function () {
    $response = $this->withHeaders(agentApiHeaders($this->user, $this->team))
        ->getJson('/api/v1/agent/containers?server_uuid='.$this->server->uuid);

    $response->assertUnprocessable()
        ->assertJson([
            'message' => 'Server is not reachable or usable.',
        ]);
});

test('agent containers endpoint hides servers from other teams', function () {
    $otherTeam = Team::factory()->create();
    $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);

    $response = $this->withHeaders(agentApiHeaders($this->user, $this->team))
        ->getJson('/api/v1/agent/containers?server_uuid='.$otherServer->uuid);

    $response->assertNotFound()
        ->assertJson([
            'message' => 'Server not found.',
        ]);
});

test('agent exec rejects unreachable servers before policy artifact issuance', function () {
    $response = $this->withHeaders(agentApiHeaders($this->user, $this->team, ['write']))
        ->postJson('/api/v1/agent/containers/app/exec', [
            'server_uuid' => $this->server->uuid,
            'command' => 'php -v',
        ]);

    $response->assertUnprocessable()
        ->assertJson([
            'message' => 'Server is not reachable or usable.',
        ]);

    expect(PolicyDecision::count())->toBe(0)
        ->and(InfraAuthorization::count())->toBe(0);
});

test('non root token cannot use emergency container exec', function () {
    $response = $this->withHeaders(agentApiHeaders($this->user, $this->team, ['write']))
        ->postJson('/api/v1/agent/containers/app/exec', [
            'server_uuid' => $this->server->uuid,
            'command' => 'php -v',
            'emergency' => true,
            'emergency_reason' => 'break glass',
        ]);

    $response->assertForbidden()
        ->assertJson([
            'message' => 'Root API token is required for emergency container exec.',
        ]);
});

test('agent exec creates consumes and executes a policy backed authorization artifact', function () {
    $functionalServer = Server::factory()->create([
        'team_id' => $this->team->id,
        'ip' => '203.0.113.10',
    ]);
    ServerSetting::updateOrCreate(['server_id' => $functionalServer->id], [
        'server_id' => $functionalServer->id,
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
    ]);
    $functionalServer->refresh();

    $this->mock(AgentContainerService::class, function ($mock) use ($functionalServer) {
        $mock->shouldReceive('exec')
            ->once()
            ->withArgs(fn (Server $server, string $container, string $command, int $timeout) => $server->id === $functionalServer->id
                && $container === 'app'
                && $command === 'php -v'
                && $timeout === 15)
            ->andReturn('PHP 8.4.0');
    });

    $response = $this->withHeaders(agentApiHeaders($this->user, $this->team, ['write']))
        ->postJson('/api/v1/agent/containers/app/exec', [
            'server_uuid' => $functionalServer->uuid,
            'command' => 'php -v',
        ]);

    $response->assertOk();

    $decision = PolicyDecision::first();
    $authorization = InfraAuthorization::first();

    expect($decision)->not->toBeNull()
        ->and($authorization)->not->toBeNull()
        ->and($authorization->policy_decision_id)->toBe($decision->id)
        ->and($authorization->status)->toBe('consumed')
        ->and($authorization->consumed_by_user_id)->toBe($this->user->id);

    $entry = InfraLedger::query()
        ->where('event_type', InfraAuthorizationService::CAPABILITY_CONTAINER_EXEC)
        ->first();

    expect($entry)->not->toBeNull()
        ->and(data_get($entry->payload, 'command_hash'))->toBe(hash('sha256', 'php -v'))
        ->and(data_get($entry->input_state, 'policy_decision_id'))->toBe($decision->uuid)
        ->and(data_get($entry->input_state, 'authorization_artifact_id'))->toBe($authorization->uuid)
        ->and(data_get($entry->output_state, 'executed'))->toBeTrue()
        ->and(data_get($entry->output_state, 'output_hash'))->toBe(hash('sha256', 'PHP 8.4.0'));
});

test('container exec authorization artifact is consumable only once', function () {
    $service = app(InfraAuthorizationService::class);
    $decision = policyDecisionForContainerExec($this->user, $this->server);
    $authorization = $service->issueContainerExec(
        decision: $decision,
        server: $this->server,
        container: 'app',
        command: 'php -v',
        ttlSeconds: 300,
        timeout: 15,
    );

    $consumed = $service->consumeContainerExec(
        authorizationId: $authorization->uuid,
        user: $this->user,
        server: $this->server,
        container: 'app',
        command: 'php -v',
        timeout: 15,
    );

    expect($consumed->status)->toBe('consumed')
        ->and($consumed->consumed_at)->not->toBeNull()
        ->and($consumed->policy_decision_id)->toBe($decision->id);

    expect(fn () => $service->consumeContainerExec(
        authorizationId: $authorization->uuid,
        user: $this->user,
        server: $this->server,
        container: 'app',
        command: 'php -v',
        timeout: 15,
    ))->toThrow(InvalidArgumentException::class, 'AuthorizationArtifact has already been consumed or revoked.');
});

test('container exec authorization rejects command drift', function () {
    $service = app(InfraAuthorizationService::class);
    $decision = policyDecisionForContainerExec($this->user, $this->server);
    $authorization = $service->issueContainerExec(
        decision: $decision,
        server: $this->server,
        container: 'app',
        command: 'php -v',
    );

    expect(fn () => $service->consumeContainerExec(
        authorizationId: $authorization->uuid,
        user: $this->user,
        server: $this->server,
        container: 'app',
        command: 'whoami',
        timeout: 15,
    ))->toThrow(InvalidArgumentException::class, 'AuthorizationArtifact command scope mismatch.');

    expect(InfraAuthorization::find($authorization->id)->status)->toBe('issued');
});

test('policy engine evaluates container exec without persistence side effects', function () {
    $payload = app(PolicyEngine::class)->evaluateContainerExec(
        user: $this->user,
        server: $this->server,
        container: 'app',
        command: 'php -v',
        timeout: 15,
        riskContext: ['risk_level' => 'LOW'],
    );

    expect($payload['decision'])->toBe('allow')
        ->and($payload['capability'])->toBe(InfraAuthorizationService::CAPABILITY_CONTAINER_EXEC)
        ->and(PolicyDecision::count())->toBe(0)
        ->and(InfraAuthorization::count())->toBe(0);
});

test('recorded policy decisions are immutable', function () {
    $decision = policyDecisionForContainerExec($this->user, $this->server);

    $updated = $decision->forceFill(['decision' => 'deny'])->save();

    expect($updated)->toBeFalse()
        ->and($decision->refresh()->decision)->toBe('allow');
});

test('authorization artifact cannot be issued from a denied policy decision', function () {
    $decision = PolicyDecision::create(app(PolicyEngine::class)->evaluateContainerExec(
        user: $this->user,
        server: $this->server,
        container: 'app',
        command: 'php -v',
        timeout: 15,
        riskContext: ['risk_level' => 'HIGH'],
    ));

    expect(fn () => app(InfraAuthorizationService::class)->issueContainerExec(
        decision: $decision,
        server: $this->server,
        container: 'app',
        command: 'php -v',
    ))->toThrow(InvalidArgumentException::class, 'PolicyDecision does not allow container exec.');

    expect(InfraAuthorization::count())->toBe(0);
});

test('emergency container exec records constitutional violation lineage', function () {
    app(InfraAuthorizationService::class)->recordEmergencyContainerExec(
        user: $this->user,
        server: $this->server,
        container: 'app',
        command: 'php -v',
        reason: 'recover production access',
    );

    $entry = InfraLedger::query()
        ->where('event_type', 'constitutional.violation.emergency_exec')
        ->first();

    expect($entry)->not->toBeNull()
        ->and(data_get($entry->payload, 'capability'))->toBe(InfraAuthorizationService::CAPABILITY_CONTAINER_EXEC)
        ->and(data_get($entry->output_state, 'constitutional_violation'))->toBeTrue();
});

test('agent container service rejects unsafe container names and commands before ssh', function () {
    $service = new AgentContainerService;

    expect(fn () => $service->logs($this->server, 'bad;name'))
        ->toThrow(InvalidArgumentException::class, 'Invalid container name.');

    expect(fn () => $service->exec($this->server, 'app', 'echo ok; rm -rf /'))
        ->toThrow(InvalidArgumentException::class, 'Command contains shell-unsafe characters.');
});
