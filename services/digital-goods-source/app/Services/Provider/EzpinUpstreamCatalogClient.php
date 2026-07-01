<?php

namespace App\Services\Provider;

use Illuminate\Support\Facades\Http;

class EzpinUpstreamCatalogClient
{
    private const DEFAULT_BASE_URL = 'https://api.ezpaypin.com/vendors/v2';

    private string $baseUrl;

    public function __construct()
    {
        $configured = (string) config('digital-goods-source.providers.ezpin.base_url', '');
        $this->baseUrl = rtrim($configured !== '' ? $configured : self::DEFAULT_BASE_URL, '/');
    }

    /**
     * @return array{catalog: array<int, array<string, mixed>>, retailer: array<int, array<string, mixed>>}
     */
    public function pullCatalogs(): array
    {
        $token = $this->token();
        $client = Http::acceptJson()
            ->withToken($token)
            ->baseUrl($this->baseUrl)
            ->connectTimeout(15)
            ->timeout(120);

        return [
            'catalog' => $this->paginate($client, '/catalogs/'),
            'retailer' => $this->paginate($client, '/retailer_products/'),
        ];
    }

    private function token(): string
    {
        $clientId = (string) config('digital-goods-source.providers.ezpin.client_id');
        $secretKey = (string) config('digital-goods-source.providers.ezpin.secret_key');

        if ($clientId === '' || $secretKey === '') {
            throw new \RuntimeException('EZPIN_CLIENT_ID and EZPIN_SECRET_KEY are required for upstream catalog pull.');
        }

        $response = Http::acceptJson()
            ->baseUrl($this->baseUrl)
            ->connectTimeout(15)
            ->timeout(30)
            ->post('/auth/token/', [
                'client_id' => $clientId,
                'secret_key' => $secretKey,
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('EZPin token request failed: '.$response->body());
        }

        $token = (string) $response->json('access');
        if ($token === '') {
            throw new \RuntimeException('EZPin token response did not include an access token.');
        }

        return $token;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function paginate(\Illuminate\Http\Client\PendingRequest $client, string $path): array
    {
        $items = [];
        $limit = 100;
        $offset = 0;

        do {
            $response = $client->get($path, [
                'limit' => $limit,
                'offset' => $offset,
            ]);

            if ($response->failed()) {
                throw new \RuntimeException("EZPin catalog request failed for {$path}: ".$response->body());
            }

            $payload = $response->json();
            $results = is_array(data_get($payload, 'results')) ? data_get($payload, 'results') : [];
            $items = array_merge($items, $results);

            $count = (int) data_get($payload, 'count', count($items));
            $offset += $limit;
            $hasNext = filled(data_get($payload, 'next')) || count($items) < $count;
        } while ($hasNext && $results !== []);

        return $items;
    }
}
