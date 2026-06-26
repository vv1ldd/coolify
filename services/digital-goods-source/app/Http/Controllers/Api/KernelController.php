<?php

namespace App\Http\Controllers\Api;

use App\Models\KernelOrder;
use App\Models\KernelPartner;
use App\Models\Provider;
use App\Models\ProviderProduct;
use App\Models\SourceLedgerEntry;
use App\Services\KernelFinanceService;
use App\Services\Provider\ExternalProviderProxy;
use App\Services\Provider\ProviderCatalogAggregator;
use App\Services\SourceLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class KernelController extends Controller
{
    public function __construct(
        private readonly ProviderCatalogAggregator $catalog,
        private readonly KernelFinanceService $finance,
        private readonly ExternalProviderProxy $externalProxy,
        private readonly SourceLedgerService $sourceLedger,
    ) {
    }

    public function status(): JsonResponse
    {
        $ledgerHead = $this->sourceLedger->head();

        return response()->json([
            'service' => config('digital-goods-source.service', 'digital-goods-source'),
            'runtime_version' => config('digital-goods-source.runtime_version', '1.0.0'),
            'kernel_protocol_version' => config('digital-goods-source.kernel_protocol_version', 'v1'),
            'provider_contract_version' => config('digital-goods-source.provider_contract_version', 'v1'),
            'provider_authority' => 'exclusive',
            'providers_count' => Provider::query()->where('is_active', true)->count(),
            'last_catalog_sync_at' => Provider::query()->whereNotNull('last_sync_at')->max('last_sync_at'),
            'ledger_head' => $ledgerHead['head_hash'],
            'ledger_events_count' => $ledgerHead['events_count'],
        ]);
    }

    public function ledgerHead(Request $request): JsonResponse
    {
        return response()->json($this->sourceLedger->head($request->query('scope')));
    }

    public function ledgerEvents(Request $request): JsonResponse
    {
        $limit = min(max((int) $request->query('limit', 100), 1), 500);
        $afterId = max((int) $request->query('after_id', 0), 0);
        $events = SourceLedgerEntry::query()
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        return response()->json([
            'ledger' => 'digital-goods-source',
            'after_id' => $afterId,
            'next_after_id' => $events->last()?->id ?? $afterId,
            'events' => $events->map(fn (SourceLedgerEntry $entry): array => $this->sourceLedger->receipt($entry))->values(),
        ]);
    }

    public function unifiedCatalog(Request $request, string $provider): JsonResponse
    {
        $record = $this->catalog->resolveProvider($provider);
        if (! $record) {
            return response()->json([
                'success' => true,
                'provider' => ['type' => $provider],
                'disabled' => true,
                'count' => 0,
                'items' => [],
            ]);
        }

        $payload = $this->catalog->unifiedCatalog($record, $request->boolean('include_inactive'));
        if ($provider !== $record->type) {
            $payload['provider']['requested_type'] = $provider;
        }

        if (! $request->boolean('include_raw')) {
            $payload['items'] = collect($payload['items'])
                ->map(function (array $item): array {
                    unset($item['raw_data']);

                    return $item;
                })
                ->all();
        }

        return response()->json($payload);
    }

    public function exchangeRates(string $provider): JsonResponse
    {
        return response()->json([
            'success' => true,
            'provider' => $provider,
            'data' => [
                ['code' => 'USD', 'rate_to_rub' => (float) env('WILDFLOW_RUB_PER_USD', 100)],
                ['code' => 'RUB', 'rate_to_rub' => 1.0],
            ],
        ]);
    }

    public function checkAvailability(Request $request, string $provider, string $sku): JsonResponse
    {
        $quantity = max(1, (int) ($request->query('quantity') ?: $request->query('item_count') ?: 1));
        $product = $this->resolveProviderProduct($provider, $sku);
        $partner = $this->targetPartner($request);
        $requiredUsd = $this->orderCostUsd($product, (float) ($request->query('price') ?? 0), $quantity);
        $balanceCheck = $this->finance->partnerBalanceCheck($partner, $requiredUsd);

        $available = (bool) ($product?->is_active ?? false);
        $source = 'local-projection';

        if ($request->boolean('live') && ($record = $this->catalog->resolveProvider($provider))) {
            $proxied = $this->externalProxy->checkAvailability($record, $sku, $quantity, $request->query('price') !== null ? (float) $request->query('price') : null);
            if (is_array($proxied)) {
                $available = (bool) data_get($proxied, 'available', data_get($proxied, 'availability', $available));
                $source = 'provider-proxy';
            }
        }

        return response()->json([
            'success' => $product || $available,
            'provider' => $provider,
            'service_sku' => $sku,
            'available' => $available,
            'availability' => [
                'availability' => $available,
                'available' => $available,
                'requested' => $quantity,
                'affordable' => $balanceCheck['affordable'] ?? null,
                'required_usd' => $balanceCheck['required_usd'] ?? null,
                'available_usd' => $balanceCheck['available_usd'] ?? null,
                'detail' => $available ? 'Available from Digital Goods Source.' : 'Product is inactive or missing.',
            ],
            'source' => $source,
        ], $product || $available ? 200 : 404);
    }

    public function checkAvailabilityFromPayload(Request $request, string $provider): JsonResponse
    {
        $data = $request->validate([
            'sku' => 'required_without:service_sku|string',
            'service_sku' => 'required_without:sku|string',
            'quantity' => 'nullable|integer|min:1',
            'item_count' => 'nullable|integer|min:1',
            'price' => 'nullable|numeric|min:0',
            'live' => 'nullable|boolean',
        ]);

        $request->query->set('quantity', (string) ($data['quantity'] ?? $data['item_count'] ?? 1));
        if (array_key_exists('price', $data)) {
            $request->query->set('price', (string) $data['price']);
        }
        if (array_key_exists('live', $data)) {
            $request->query->set('live', $data['live'] ? '1' : '0');
        }

        return $this->checkAvailability($request, $provider, (string) ($data['service_sku'] ?? $data['sku']));
    }

    public function placeOrder(Request $request, string $provider): JsonResponse
    {
        $data = $request->validate([
            'service_sku' => 'required_without:sku|string',
            'sku' => 'required_without:service_sku|string',
            'quantity' => 'nullable|integer|min:1|max:100',
            'price' => 'nullable|numeric|min:0',
            'referenceCode' => 'nullable|string|max:160',
            'reference_code' => 'nullable|string|max:160',
            'destination' => 'nullable|string|max:255',
            'seller_id' => 'nullable|string|max:128',
            'seller_name' => 'nullable|string|max:255',
        ]);

        $sku = (string) ($data['service_sku'] ?? $data['sku']);
        $reference = (string) ($data['referenceCode'] ?? $data['reference_code'] ?? Str::uuid());
        $quantity = max(1, (int) ($data['quantity'] ?? 1));
        $product = $this->resolveProviderProduct($provider, $sku);
        $partner = $this->targetPartner($request);

        if (! $partner || ! $partner->is_active) {
            return response()->json(['success' => false, 'message' => 'Active partner is required for kernel orders.'], 403);
        }

        if ($product && ! $product->is_active) {
            return response()->json(['success' => false, 'message' => 'Product is inactive.'], 400);
        }

        $existing = KernelOrder::query()
            ->where('provider', $provider)
            ->where('reference', $reference)
            ->first();

        if ($existing) {
            $receipt = $this->sourceLedger->receiptForReference($reference, 'source.order.accepted')
                ?? $this->sourceLedger->receipt($this->sourceLedger->record(
                    eventType: 'source.order.idempotent_reused',
                    entity: $existing,
                    payload: ['reference' => $reference, 'provider' => $provider],
                    scope: $this->sourceLedger->scopeFor($provider, $partner?->external_id),
                    provider: $provider,
                    partnerExternalId: $partner?->external_id,
                    reference: $reference,
                ));

            return response()->json([
                'success' => $existing->status !== 'failed',
                'idempotent' => true,
                'type' => 'digital-goods-source',
                'order' => $this->orderPayload($existing),
                'source_ledger_receipt' => $receipt,
            ], $existing->status === 'failed' ? 500 : 200);
        }

        $unitPrice = (float) ($data['price'] ?? $product?->retail_price ?? $product?->purchase_price ?? 0);
        $requiredUsd = $this->orderCostUsd($product, $unitPrice, $quantity);
        if ($requiredUsd <= 0) {
            return response()->json(['success' => false, 'message' => 'Order price is required.'], 422);
        }

        $balanceCheck = $this->finance->partnerBalanceCheck($partner, $requiredUsd);
        if (! ($balanceCheck['affordable'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient partner balance for kernel order.',
                'required_usd' => $balanceCheck['required_usd'] ?? $requiredUsd,
                'available_usd' => $balanceCheck['available_usd'] ?? 0,
                'balance_currency' => $partner->currency,
            ], 402);
        }

        $order = DB::transaction(function () use ($partner, $provider, $reference, $sku, $quantity, $unitPrice, $requiredUsd, $data): KernelOrder {
            $this->finance->debitForOrder($partner, $requiredUsd);

            return KernelOrder::create([
                'kernel_partner_id' => $partner->id,
                'provider' => $provider,
                'reference' => $reference,
                'service_sku' => $sku,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_amount' => $requiredUsd,
                'currency' => 'USD',
                'status' => 'accepted',
                'cards' => [],
                'payload' => $data,
            ]);
        });

        return response()->json([
            'success' => true,
            'idempotent' => false,
            'type' => 'digital-goods-source',
            'order' => $this->orderPayload($order),
            'source_ledger_receipt' => $this->sourceLedger->receipt($this->sourceLedger->record(
                eventType: 'source.order.accepted',
                entity: $order,
                payload: [
                    'reference' => $reference,
                    'service_sku' => $sku,
                    'quantity' => $quantity,
                    'total_amount' => $requiredUsd,
                    'currency' => 'USD',
                ],
                outputState: $this->orderPayload($order),
                scope: $this->sourceLedger->scopeFor($provider, $partner->external_id),
                provider: $provider,
                partnerExternalId: $partner->external_id,
                reference: $reference,
            )),
        ]);
    }

    public function normalizedCards(string $provider, string $reference): JsonResponse
    {
        $order = KernelOrder::query()
            ->where('provider', $provider)
            ->where('reference', $reference)
            ->first();

        return response()->json([
            'success' => (bool) $order,
            'provider' => $provider,
            'reference' => $reference,
            'cards' => $order?->cards ?: [],
        ], $order ? 200 : 404);
    }

    public function listPartners(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => KernelPartner::query()->orderBy('name')->get()->map(fn (KernelPartner $partner): array => $this->partnerPayload($partner)),
        ]);
    }

    public function syncPartner(Request $request): JsonResponse
    {
        $data = $request->validate([
            'external_id' => 'required|string|max:128',
            'name' => 'required|string|max:255',
            'api_token' => 'nullable|string|max:255',
            'financial_secret' => 'nullable|string|max:255',
            'currency' => 'nullable|string|size:3',
            'is_active' => 'nullable|boolean',
            'metadata' => 'nullable|array',
        ]);

        $partner = KernelPartner::updateOrCreate(
            ['external_id' => $data['external_id']],
            [
                'name' => $data['name'],
                'api_token' => $data['api_token'] ?? null,
                'financial_secret' => $data['financial_secret'] ?? null,
                'currency' => strtoupper($data['currency'] ?? 'USD'),
                'is_active' => $data['is_active'] ?? true,
                'metadata' => $data['metadata'] ?? [],
            ],
        );

        return response()->json([
            'success' => true,
            'data' => $this->partnerPayload($partner),
            'source_ledger_receipt' => $this->sourceLedger->receipt($this->sourceLedger->record(
                eventType: 'source.partner.synced',
                entity: $partner,
                payload: [
                    'external_id' => $partner->external_id,
                    'currency' => $partner->currency,
                    'is_active' => $partner->is_active,
                ],
                outputState: $this->partnerPayload($partner),
                scope: $this->sourceLedger->scopeFor(null, $partner->external_id),
                partnerExternalId: $partner->external_id,
                reference: $partner->external_id,
            )),
        ]);
    }

    public function grantCredit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'reference' => 'required|string|max:160',
            'terminal_id' => 'nullable|string|max:128',
        ]);
        $partner = $this->targetPartner($request, $data['terminal_id'] ?? null);
        if (! $partner) {
            return response()->json(['success' => false, 'message' => 'Partner not found.'], 404);
        }

        $result = $this->finance->grantCredit($partner, (float) $data['amount'], $data['reference']);
        $eventType = ($result['idempotent'] ?? false) ? 'source.credit.grant.idempotent_reused' : 'source.credit.granted';

        return response()->json($result + [
            'source_ledger_receipt' => $this->sourceLedger->receipt($this->sourceLedger->record(
                eventType: $eventType,
                entity: $partner,
                payload: [
                    'amount' => (float) $data['amount'],
                    'reference' => $data['reference'],
                    'idempotent' => (bool) ($result['idempotent'] ?? false),
                ],
                outputState: $result,
                scope: $this->sourceLedger->scopeFor(null, $partner->external_id),
                partnerExternalId: $partner->external_id,
                reference: $data['reference'],
            )),
        ]);
    }

    public function topUp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'reference' => 'nullable|string|max:160',
            'terminal_id' => 'nullable|string|max:128',
        ]);
        $partner = $this->targetPartner($request, $data['terminal_id'] ?? null);
        if (! $partner) {
            return response()->json(['success' => false, 'message' => 'Partner not found.'], 404);
        }

        $reference = $data['reference'] ?? 'top-up-'.Str::uuid();
        $result = $this->finance->topUp($partner, (float) $data['amount'], $reference);

        return response()->json($result + [
            'source_ledger_receipt' => $this->sourceLedger->receipt($this->sourceLedger->record(
                eventType: 'source.partner.top_up',
                entity: $partner,
                payload: [
                    'amount' => (float) $data['amount'],
                    'reference' => $reference,
                ],
                outputState: $result,
                scope: $this->sourceLedger->scopeFor(null, $partner->external_id),
                partnerExternalId: $partner->external_id,
                reference: $reference,
            )),
        ]);
    }

    public function showPartner(string $externalId): JsonResponse
    {
        $partner = KernelPartner::query()->where('external_id', $externalId)->first();

        return response()->json([
            'success' => (bool) $partner,
            'data' => $partner ? $this->partnerPayload($partner) : null,
        ], $partner ? 200 : 404);
    }

    private function resolveProviderProduct(string $provider, string $sku): ?ProviderProduct
    {
        $record = $this->catalog->resolveProvider($provider);
        if (! $record) {
            return null;
        }

        return ProviderProduct::query()
            ->where('provider_id', $record->id)
            ->where(fn ($query) => $query->where('sku', $sku)->orWhere('market_sku', $sku))
            ->first();
    }

    private function targetPartner(Request $request, ?string $overrideExternalId = null): ?KernelPartner
    {
        $partner = $request->attributes->get('kernel_partner');
        if ($partner instanceof KernelPartner) {
            return $partner;
        }

        $externalId = $overrideExternalId ?: (string) $request->header('X-Client-Id');
        if ($externalId === '') {
            return null;
        }

        return KernelPartner::query()->where('external_id', $externalId)->first();
    }

    private function orderCostUsd(?ProviderProduct $product, float $providedPrice, int $quantity): float
    {
        $unit = $providedPrice ?: (float) ($product?->purchase_price ?: $product?->retail_price ?: 0);

        return round($unit * max(1, $quantity), 2);
    }

    private function orderPayload(KernelOrder $order): array
    {
        return [
            'referenceCode' => $order->reference,
            'order_id' => $order->reference,
            'provider' => $order->provider,
            'service_sku' => $order->service_sku,
            'status' => $order->status,
            'status_text' => $order->status,
            'is_completed' => filled($order->cards),
            'total_amount' => (float) $order->total_amount,
            'currency' => $order->currency,
        ];
    }

    private function partnerPayload(KernelPartner $partner): array
    {
        return [
            'external_id' => $partner->external_id,
            'name' => $partner->name,
            'balance' => (float) $partner->available_balance,
            'reserved_balance' => (float) $partner->reserved_balance,
            'currency' => $partner->currency,
            'is_active' => (bool) $partner->is_active,
        ];
    }
}
