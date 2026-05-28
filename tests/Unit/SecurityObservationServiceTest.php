<?php

use App\Models\SecurityObservation;
use App\Models\Team;
use App\Services\InfraLedgerService;
use App\Services\SecurityObservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);

uses(RefreshDatabase::class);

test('security observation source hash is deterministic and keyed', function () {
    config()->set('app.key', 'base64:dGVzdC1zZWN1cml0eS1rZXk=');

    $service = new SecurityObservationService(Mockery::mock(InfraLedgerService::class));

    expect($service->sourceHash('203.0.113.10'))
        ->toBe($service->sourceHash('203.0.113.10'))
        ->not->toBe($service->sourceHash('203.0.113.11'));
});

test('security observation source score sums signals in a rolling window', function () {
    $team = Team::factory()->create();
    $service = new SecurityObservationService(Mockery::mock(InfraLedgerService::class));
    $sourceHash = $service->sourceHash('203.0.113.10');

    SecurityObservation::create([
        'team_id' => $team->id,
        'source_ip' => '203.0.113.10',
        'source_hash' => $sourceHash,
        'layer' => 'L2',
        'signal' => 'path.probe.env',
        'score' => 50,
        'action' => 'block',
        'observed_at' => now()->subMinutes(5),
    ]);
    SecurityObservation::create([
        'team_id' => $team->id,
        'source_ip' => '203.0.113.10',
        'source_hash' => $sourceHash,
        'layer' => 'L1',
        'signal' => 'fingerprint.suspicious_user_agent',
        'score' => 20,
        'action' => 'observe',
        'observed_at' => now()->subMinutes(10),
    ]);
    SecurityObservation::create([
        'team_id' => $team->id,
        'source_ip' => '203.0.113.10',
        'source_hash' => $sourceHash,
        'layer' => 'L1',
        'signal' => 'fingerprint.old',
        'score' => 20,
        'action' => 'observe',
        'observed_at' => now()->subMinutes(20),
    ]);

    expect($service->sourceScore($team->id, $sourceHash, 15))->toBe(70);
});
