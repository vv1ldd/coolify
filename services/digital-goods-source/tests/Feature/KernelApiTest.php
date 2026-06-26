<?php

namespace Tests\Feature;

use App\Models\KernelOrder;
use App\Models\KernelPartner;
use App\Models\Provider;
use App\Models\SourceLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KernelApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_exposes_versioned_provider_contract(): void
    {
        $this->getJson('/api/v1/status')
            ->assertOk()
            ->assertJsonPath('service', 'digital-goods-source')
            ->assertJsonPath('kernel_protocol_version', 'v1')
            ->assertJsonPath('provider_contract_version', 'v1')
            ->assertJsonPath('provider_authority', 'exclusive')
            ->assertJsonPath('ledger_events_count', 0);
    }

    public function test_kernel_routes_reject_missing_token(): void
    {
        $this->getJson('/api/v1/providers/wildflow/unified-catalog')
            ->assertUnauthorized();
    }

    public function test_unified_catalog_projects_provider_products_and_aliases_ezpin_to_wildflow(): void
    {
        $provider = Provider::create(['name' => 'Wildflow', 'type' => 'wildflow']);
        $provider->products()->create([
            'sku' => 'SKU-1',
            'market_sku' => 'MARKET-1',
            'name' => 'Gift Card',
            'purchase_price' => 10,
            'retail_price' => 12,
            'min_price' => 10,
            'max_price' => 12,
            'currency' => 'USD',
        ]);

        $this->withToken('kernel-platform-token')
            ->getJson('/api/v1/providers/ezpin/unified-catalog')
            ->assertOk()
            ->assertJsonPath('provider.type', 'wildflow')
            ->assertJsonPath('provider.requested_type', 'ezpin')
            ->assertJsonPath('items.0.service_sku', 'SKU-1')
            ->assertJsonPath('items.0.market_sku', 'MARKET-1');
    }

    public function test_financial_routes_validate_hmac_signature(): void
    {
        KernelPartner::create([
            'external_id' => 'partner-1',
            'name' => 'Partner',
            'api_token' => 'partner-token',
            'financial_secret' => 'partner-secret',
        ]);

        $this->withToken('partner-token')
            ->withHeaders(['X-Client-Id' => 'partner-1'])
            ->postJson('/api/v1/partners/top-up', ['amount' => 10])
            ->assertUnauthorized();

        $this->withToken('partner-token')
            ->withHeaders([
                'X-Client-Id' => 'partner-1',
                'X-Financial-Timestamp' => (string) time(),
                'X-Financial-Signature' => 'bad-signature',
            ])
            ->postJson('/api/v1/partners/top-up', ['amount' => 10])
            ->assertUnauthorized();
    }

    public function test_financial_routes_reject_expired_timestamp_and_replay(): void
    {
        KernelPartner::create([
            'external_id' => 'partner-replay',
            'name' => 'Partner',
            'api_token' => 'partner-token',
            'financial_secret' => 'partner-secret',
        ]);

        $body = ['amount' => 10, 'reference' => 'replay-1'];
        $json = json_encode($body, JSON_THROW_ON_ERROR);

        $expiredHeaders = $this->signedHeaders('POST', '/api/v1/partners/grant-credit', $json, 'partner-secret', time() - 600) + [
            'X-Client-Id' => 'partner-replay',
        ];
        $this->withToken('partner-token')
            ->withHeaders($expiredHeaders)
            ->postJson('/api/v1/partners/grant-credit', $body)
            ->assertUnauthorized();

        $headers = $this->signedHeaders('POST', '/api/v1/partners/grant-credit', $json, 'partner-secret', time()) + [
            'X-Client-Id' => 'partner-replay',
        ];

        $this->withToken('partner-token')
            ->withHeaders($headers)
            ->postJson('/api/v1/partners/grant-credit', $body)
            ->assertOk();

        $this->withToken('partner-token')
            ->withHeaders($headers)
            ->postJson('/api/v1/partners/grant-credit', $body)
            ->assertStatus(409)
            ->assertJsonPath('message', 'Financial signature replay rejected.')
            ->assertJsonPath('source_ledger_receipt.ledger', 'digital-goods-source')
            ->assertJsonPath('source_ledger_receipt.event_type', 'source.signature.replay_rejected');

        $this->assertDatabaseHas('source_ledger_entries', [
            'event_type' => 'source.signature.replay_rejected',
            'partner_external_id' => 'partner-replay',
        ]);
    }

    public function test_availability_reports_partner_balance_affordability(): void
    {
        $provider = Provider::create(['name' => 'Wildflow', 'type' => 'wildflow']);
        $provider->products()->create([
            'sku' => 'SKU-2',
            'name' => 'Balance Product',
            'purchase_price' => 15,
            'retail_price' => 15,
            'currency' => 'USD',
        ]);
        KernelPartner::create([
            'external_id' => 'partner-2',
            'name' => 'Partner',
            'api_token' => 'partner-token',
            'financial_secret' => 'partner-secret',
            'available_balance' => 20,
        ]);

        $this->withToken('partner-token')
            ->withHeaders(['X-Client-Id' => 'partner-2'])
            ->getJson('/api/v1/providers/wildflow/check-availability/SKU-2?quantity=1')
            ->assertOk()
            ->assertJsonPath('availability.affordable', true);
    }

    public function test_orders_are_idempotent_and_cards_are_normalized(): void
    {
        $provider = Provider::create(['name' => 'Wildflow', 'type' => 'wildflow']);
        $provider->products()->create([
            'sku' => 'SKU-3',
            'name' => 'Order Product',
            'purchase_price' => 10,
            'retail_price' => 10,
            'currency' => 'USD',
        ]);
        KernelPartner::create([
            'external_id' => 'partner-3',
            'name' => 'Partner',
            'api_token' => 'partner-token',
            'financial_secret' => 'partner-secret',
            'available_balance' => 25,
        ]);

        $body = ['service_sku' => 'SKU-3', 'quantity' => 1, 'referenceCode' => 'order-1'];
        $json = json_encode($body, JSON_THROW_ON_ERROR);
        $headers = $this->signedHeaders('POST', '/api/v1/providers/wildflow/order', $json, 'partner-secret', time()) + [
            'X-Client-Id' => 'partner-3',
        ];

        $secondHeaders = $this->signedHeaders('POST', '/api/v1/providers/wildflow/order', $json, 'partner-secret', time() + 1) + [
            'X-Client-Id' => 'partner-3',
        ];

        $this->withToken('partner-token')
            ->withHeaders($secondHeaders)
            ->postJson('/api/v1/providers/wildflow/order', $body)
            ->assertOk()
            ->assertJsonPath('idempotent', false)
            ->assertJsonPath('source_ledger_receipt.ledger', 'digital-goods-source')
            ->assertJsonPath('source_ledger_receipt.event_type', 'source.order.accepted')
            ->assertJsonPath('source_ledger_receipt.reference', 'order-1');

        $this->withToken('partner-token')
            ->withHeaders($headers)
            ->postJson('/api/v1/providers/wildflow/order', $body)
            ->assertOk()
            ->assertJsonPath('idempotent', true)
            ->assertJsonPath('source_ledger_receipt.event_type', 'source.order.accepted');

        $this->getJson('/api/v1/ledger/head')
            ->assertOk()
            ->assertJsonPath('ledger', 'digital-goods-source')
            ->assertJsonPath('events_count', 1);

        $this->getJson('/api/v1/ledger/events')
            ->assertOk()
            ->assertJsonPath('events.0.event_type', 'source.order.accepted');

        KernelOrder::query()->where('reference', 'order-1')->update([
            'cards' => [['pin' => '1234', 'serial' => 'S1']],
        ]);

        $this->withToken('kernel-platform-token')
            ->getJson('/api/v1/providers/wildflow/orders/order-1/normalized-cards')
            ->assertOk()
            ->assertJsonPath('cards.0.pin', '1234');
    }

    public function test_credit_grant_is_idempotent(): void
    {
        KernelPartner::create([
            'external_id' => 'partner-4',
            'name' => 'Partner',
            'api_token' => 'partner-token',
            'financial_secret' => 'partner-secret',
        ]);

        $body = ['amount' => 50, 'reference' => 'credit-1'];
        $json = json_encode($body, JSON_THROW_ON_ERROR);
        $headers = $this->signedHeaders('POST', '/api/v1/partners/grant-credit', $json, 'partner-secret', time()) + [
            'X-Client-Id' => 'partner-4',
        ];

        $secondHeaders = $this->signedHeaders('POST', '/api/v1/partners/grant-credit', $json, 'partner-secret', time() + 1) + [
            'X-Client-Id' => 'partner-4',
        ];

        $this->withToken('partner-token')
            ->withHeaders($secondHeaders)
            ->postJson('/api/v1/partners/grant-credit', $body)
            ->assertOk()
            ->assertJsonPath('idempotent', false)
            ->assertJsonPath('source_ledger_receipt.event_type', 'source.credit.granted');

        $this->withToken('partner-token')
            ->withHeaders($headers)
            ->postJson('/api/v1/partners/grant-credit', $body)
            ->assertOk()
            ->assertJsonPath('idempotent', true)
            ->assertJsonPath('source_ledger_receipt.event_type', 'source.credit.grant.idempotent_reused');

        $entry = SourceLedgerEntry::query()->where('event_type', 'source.credit.granted')->first();
        $this->assertNotNull($entry);
        $this->assertSame('digital-goods-source-v1', data_get($entry->meta, 'constitution'));
        $this->assertSame('provider-authority-v1', data_get($entry->meta, 'determinism'));
    }

    /**
     * @return array<string, string>
     */
    private function signedHeaders(string $method, string $path, string $body, string $secret, ?int $timestamp = null): array
    {
        $timestamp = (string) ($timestamp ?? time());

        return [
            'X-Financial-Timestamp' => $timestamp,
            'X-Financial-Signature' => hash_hmac('sha256', $timestamp.'.'.$method.'.'.$path.'.'.$body, $secret),
        ];
    }
}
