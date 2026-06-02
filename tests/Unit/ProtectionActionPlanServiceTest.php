<?php

use App\Enums\ProtectionActionType;
use App\Enums\ProtectionLevel;
use App\Models\InfraLedger;
use App\Models\Team;
use App\Services\IncidentProtection\ProtectionActionExecutor;
use App\Services\IncidentProtection\ProtectionActionPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

test('critical protection level plans edge challenge and notification actions', function () {
    $team = Team::factory()->create();

    $plan = app(ProtectionActionPlanService::class)->plan($team->id, [
        'severity' => 85,
        'signals' => ['edge.request_spike', 'edge.probe_paths'],
        'domain' => 'app.example.com',
    ]);

    $actions = collect($plan->actions)->keyBy(fn ($action) => $action->type->value);

    expect($plan->level)->toBe(ProtectionLevel::CRITICAL)
        ->and($actions)->toHaveKeys([
            ProtectionActionType::SET_EDGE_POLICY_MODE->value,
            ProtectionActionType::ENABLE_CHALLENGE->value,
            ProtectionActionType::TIGHTEN_RATE_LIMIT->value,
            ProtectionActionType::NOTIFY->value,
        ])
        ->and($actions[ProtectionActionType::SET_EDGE_POLICY_MODE->value]->payload['mode'])->toBe('under_attack')
        ->and($actions[ProtectionActionType::ENABLE_CHALLENGE->value]->payload['challenge_enabled'])->toBeTrue()
        ->and($actions[ProtectionActionType::NOTIFY->value]->requiresApproval)->toBeFalse();
});

test('emergency protection level keeps provider actions approval required and dry run', function () {
    $team = Team::factory()->create();

    $plan = app(ProtectionActionPlanService::class)->plan($team->id, [
        'severity' => 99,
        'signals' => ['host.compromise_suspected'],
        'server_id' => 123,
        'provider' => 'hetzner',
    ]);

    $actions = collect($plan->actions)->keyBy(fn ($action) => $action->type->value);
    $isolate = $actions[ProtectionActionType::PROVIDER_ISOLATE_SERVER->value];
    $poweroff = $actions[ProtectionActionType::PROVIDER_POWEROFF_SERVER->value];

    expect($plan->level)->toBe(ProtectionLevel::EMERGENCY)
        ->and($isolate->requiresApproval)->toBeTrue()
        ->and($isolate->dangerous)->toBeTrue()
        ->and($isolate->dryRun)->toBeTrue()
        ->and($poweroff->requiresApproval)->toBeTrue()
        ->and($poweroff->dangerous)->toBeTrue()
        ->and($poweroff->dryRun)->toBeTrue();
});

test('destructive provider action execution without approval is blocked and audited', function () {
    $team = Team::factory()->create();
    $plan = app(ProtectionActionPlanService::class)->plan($team->id, [
        'level' => 'emergency',
        'severity' => 100,
        'signals' => ['host.compromise_suspected'],
        'server_id' => 123,
        'provider' => 'hetzner',
    ]);

    $poweroff = collect($plan->actions)
        ->first(fn ($action) => $action->type === ProtectionActionType::PROVIDER_POWEROFF_SERVER);

    $result = app(ProtectionActionExecutor::class)->execute($poweroff, [
        'team_id' => $team->id,
    ]);

    expect($result->status)->toBe('blocked')
        ->and($result->blocked)->toBeTrue()
        ->and($result->dryRun)->toBeTrue();

    expect(InfraLedger::where('team_id', $team->id)
        ->where('event_type', 'protection.action_plan.created')
        ->exists())->toBeTrue()
        ->and(InfraLedger::where('team_id', $team->id)
            ->where('event_type', 'protection.action.blocked')
            ->exists())->toBeTrue();
});
