<?php

use App\Services\Dns\CloudflareDnsException;
use App\Services\Dns\CloudflareDnsProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

test('cloudflare dns provider lists zones', function () {
    Http::fake([
        'https://api.cloudflare.com/client/v4/zones' => Http::response([
            'success' => true,
            'result' => [
                ['id' => 'zone-1', 'name' => 'example.com'],
            ],
        ]),
    ]);

    $zones = (new CloudflareDnsProvider('test-token'))->listZones();

    expect($zones)->toBe([
        ['id' => 'zone-1', 'name' => 'example.com'],
    ]);

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-token'));
});

test('cloudflare dns provider creates a record when none exists', function () {
    Http::fake([
        'https://api.cloudflare.com/client/v4/zones/zone-1/dns_records*' => Http::sequence()
            ->push(['success' => true, 'result' => []])
            ->push(['success' => true, 'result' => ['id' => 'record-1', 'type' => 'A', 'name' => 'app.example.com']]),
    ]);

    $record = (new CloudflareDnsProvider('test-token'))->upsertRecord('zone-1', [
        'type' => 'A',
        'name' => 'app.example.com',
        'content' => '203.0.113.10',
        'ttl' => 1,
        'proxied' => true,
    ]);

    expect(data_get($record, 'id'))->toBe('record-1');

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && data_get($request->data(), 'proxied') === true
        && data_get($request->data(), 'type') === 'A');
});

test('cloudflare dns provider updates an existing record', function () {
    Http::fake([
        'https://api.cloudflare.com/client/v4/zones/zone-1/dns_records*' => Http::sequence()
            ->push(['success' => true, 'result' => [['id' => 'record-1', 'type' => 'CNAME', 'name' => 'app.example.com']]])
            ->push(['success' => true, 'result' => ['id' => 'record-1', 'type' => 'CNAME', 'name' => 'app.example.com']]),
    ]);

    $record = (new CloudflareDnsProvider('test-token'))->upsertRecord('zone-1', [
        'type' => 'CNAME',
        'name' => 'app.example.com',
        'content' => 'edge.example.net',
        'ttl' => 300,
        'proxied' => false,
    ]);

    expect(data_get($record, 'id'))->toBe('record-1');

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && str_ends_with($request->url(), '/zones/zone-1/dns_records/record-1'));
});

test('cloudflare dns provider does not send proxied for txt records', function () {
    Http::fake([
        'https://api.cloudflare.com/client/v4/zones/zone-1/dns_records*' => Http::sequence()
            ->push(['success' => true, 'result' => []])
            ->push(['success' => true, 'result' => ['id' => 'txt-1', 'type' => 'TXT', 'name' => 'example.com']]),
    ]);

    (new CloudflareDnsProvider('test-token'))->upsertRecord('zone-1', [
        'type' => 'TXT',
        'name' => 'example.com',
        'content' => 'v=spf1 -all',
        'ttl' => 300,
        'proxied' => true,
    ]);

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && ! array_key_exists('proxied', $request->data()));
});

test('cloudflare dns provider surfaces api errors without token data', function () {
    Http::fake([
        'https://api.cloudflare.com/client/v4/zones' => Http::response([
            'success' => false,
            'errors' => [
                ['message' => 'Invalid API token'],
            ],
        ], 403),
    ]);

    (new CloudflareDnsProvider('secret-token'))->listZones();
})->throws(CloudflareDnsException::class, 'Invalid API token');
