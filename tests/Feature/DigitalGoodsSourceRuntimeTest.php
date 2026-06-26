<?php

use App\Models\InstanceSettings;
use App\Models\InfraLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::create(['id' => 0]));
    config()->set('sovereign.digital_goods_source.enabled', true);
    config()->set('sovereign.digital_goods_source.url', 'http://digital-goods-source:8080');
    config()->set('sovereign.digital_goods_source.status_urls', ['http://digital-goods-source:8080', 'http://127.0.0.1:8091']);
    config()->set('sovereign.digital_goods_source.kernel_protocol_version', 'v1');
    config()->set('sovereign.digital_goods_source.provider_contract_version', 'v1');
});

test('digital goods source runtime status validates expected protocol versions', function () {
    Http::fake([
        'http://digital-goods-source:8080/api/v1/status' => Http::response([
            'service' => 'digital-goods-source',
            'runtime_version' => '1.0.0',
            'kernel_protocol_version' => 'v1',
            'provider_contract_version' => 'v1',
            'provider_authority' => 'exclusive',
            'providers_count' => 2,
            'last_catalog_sync_at' => now()->toIso8601String(),
            'ledger_head' => 'source-head-1',
            'ledger_events_count' => 4,
        ]),
    ]);

    $this->getJson('/digital-goods-source/status')
        ->assertOk()
        ->assertJsonPath('service', 'digital-goods-source')
        ->assertJsonPath('provider_authority', 'exclusive')
        ->assertJsonPath('reachable', true)
        ->assertJsonPath('ready', true)
        ->assertJsonPath('remote.kernel_protocol_version', 'v1')
        ->assertJsonPath('remote.provider_contract_version', 'v1')
        ->assertJsonPath('remote.ledger_head', 'source-head-1');

    $entry = InfraLedger::query()->latest('id')->first();
    expect($entry?->event_type)->toBe('digital_goods_source.health.checked')
        ->and(data_get($entry?->payload, 'remote_ledger_head'))->toBe('source-head-1')
        ->and(data_get($entry?->payload, 'provider_order_id'))->toBeNull();
});

test('digital goods source runtime status fails readiness on protocol mismatch', function () {
    Http::fake([
        'http://digital-goods-source:8080/api/v1/status' => Http::response([
            'service' => 'digital-goods-source',
            'runtime_version' => '1.0.0',
            'kernel_protocol_version' => 'v0',
            'provider_contract_version' => 'v1',
        ]),
    ]);

    $this->getJson('/digital-goods-source/status')
        ->assertOk()
        ->assertJsonPath('reachable', true)
        ->assertJsonPath('ready', false)
        ->assertJsonPath('remote.kernel_protocol_version', 'v0');

    expect(InfraLedger::query()->latest('id')->value('event_type'))->toBe('digital_goods_source.protocol.mismatch');
});
