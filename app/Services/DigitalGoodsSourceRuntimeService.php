<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class DigitalGoodsSourceRuntimeService
{
    public function status(): array
    {
        $configured = [
            'service' => 'digital-goods-source',
            'runtime_version' => (string) config('sovereign.digital_goods_source.runtime_version', '1.0.0'),
            'kernel_protocol_version' => (string) config('sovereign.digital_goods_source.kernel_protocol_version', 'v1'),
            'provider_contract_version' => (string) config('sovereign.digital_goods_source.provider_contract_version', 'v1'),
            'provider_authority' => 'exclusive',
            'url' => $this->baseUrl(),
            'enabled' => (bool) config('sovereign.digital_goods_source.enabled', true),
        ];

        if (! $configured['enabled']) {
            return $configured + [
                'reachable' => false,
                'ready' => false,
                'message' => 'Digital Goods Source runtime is disabled.',
            ];
        }

        try {
            [$response, $checkedUrl, $errors] = $this->fetchStatus();

            $payload = $response?->json();
            $ready = $response?->ok()
                && data_get($payload, 'kernel_protocol_version') === $configured['kernel_protocol_version']
                && data_get($payload, 'provider_contract_version') === $configured['provider_contract_version'];

            return $configured + [
                'reachable' => (bool) $response?->ok(),
                'ready' => $ready,
                'checked_url' => $checkedUrl,
                'checked_urls' => $this->statusUrls(),
                'errors' => $errors,
                'remote' => is_array($payload) ? $payload : [],
            ];
        } catch (\Throwable $error) {
            return $configured + [
                'reachable' => false,
                'ready' => false,
                'error' => $error->getMessage(),
            ];
        }
    }

    public function baseUrl(): string
    {
        return rtrim((string) config('sovereign.digital_goods_source.url', 'http://digital-goods-source:8080'), '/');
    }

    /**
     * @return array{0: \Illuminate\Http\Client\Response|null, 1: string|null, 2: array<string, string>}
     */
    private function fetchStatus(): array
    {
        $errors = [];
        foreach ($this->statusUrls() as $url) {
            try {
                $response = Http::timeout((int) config('sovereign.digital_goods_source.timeout', 10))
                    ->acceptJson()
                    ->get($url.'/api/v1/status');

                if ($response->ok()) {
                    return [$response, $url, $errors];
                }

                $errors[$url] = 'HTTP '.$response->status();
            } catch (\Throwable $error) {
                $errors[$url] = $error->getMessage();
            }
        }

        return [null, null, $errors];
    }

    /**
     * @return array<int, string>
     */
    private function statusUrls(): array
    {
        return collect(config('sovereign.digital_goods_source.status_urls', []))
            ->prepend($this->baseUrl())
            ->filter()
            ->map(fn ($url) => rtrim((string) $url, '/'))
            ->unique()
            ->values()
            ->all();
    }
}
