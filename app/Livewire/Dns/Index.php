<?php

namespace App\Livewire\Dns;

use App\Models\DnsZone;
use App\Services\Dns\CloudflareDnsProvider;
use App\Services\Dns\DnsZoneService;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Index extends Component
{
    public Collection $zones;

    public string $provider = 'cloudflare';

    public string $name = '';

    public ?string $provider_zone_id = null;

    public string $api_token = '';

    public array $providerZones = [];

    public ?string $selectedProviderZoneId = null;

    public function mount(): void
    {
        $this->refreshZones();
    }

    protected function rules(): array
    {
        return [
            'provider' => ['required', 'string', Rule::in(['cloudflare'])],
            'name' => ['required', 'string', 'max:255'],
            'provider_zone_id' => ['nullable', 'string', 'max:255'],
            'api_token' => ['required', 'string'],
        ];
    }

    public function createZone(DnsZoneService $dnsZones)
    {
        $this->validate();

        try {
            $zone = $dnsZones->createZone(currentTeam()->id, [
                'provider' => $this->provider,
                'name' => $this->name,
                'provider_zone_id' => $this->provider_zone_id,
                'api_token' => $this->api_token,
            ]);

            $this->reset(['name', 'provider_zone_id', 'api_token']);
            $this->provider = 'cloudflare';
            $this->refreshZones();

            $this->dispatch('success', 'DNS zone created.', 'The Cloudflare token was stored encrypted and is not shown again.');

            return redirect()->route('dns.show', ['zone_uuid' => $zone->uuid]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function loadProviderZones(): void
    {
        $this->validateOnly('api_token');

        try {
            $this->providerZones = collect((new CloudflareDnsProvider($this->api_token))->listZones())
                ->map(fn (array $zone): array => [
                    'id' => (string) data_get($zone, 'id'),
                    'name' => (string) data_get($zone, 'name'),
                    'status' => (string) data_get($zone, 'status'),
                ])
                ->filter(fn (array $zone): bool => $zone['id'] !== '' && $zone['name'] !== '')
                ->sortBy('name')
                ->values()
                ->all();

            if ($this->providerZones === []) {
                $this->dispatch('warning', 'No zones found.', 'The token is valid, but Cloudflare did not return any zones.');

                return;
            }

            $this->dispatch('success', 'Cloudflare zones loaded.', 'Select a zone to fill its name and Zone ID.');
        } catch (\Throwable $e) {
            $this->providerZones = [];
            $this->selectedProviderZoneId = null;

            handleError($e, $this);
        }
    }

    public function updatedSelectedProviderZoneId(?string $zoneId): void
    {
        $zone = collect($this->providerZones)->firstWhere('id', $zoneId);
        if (! $zone) {
            return;
        }

        $this->provider_zone_id = $zone['id'];
        $this->name = $zone['name'];
    }

    private function refreshZones(): void
    {
        $this->zones = DnsZone::whereTeamId(currentTeam()->id)
            ->withCount('records')
            ->orderBy('name')
            ->get();
    }

    public function render()
    {
        return view('livewire.dns.index');
    }
}
