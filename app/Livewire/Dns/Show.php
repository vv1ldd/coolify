<?php

namespace App\Livewire\Dns;

use App\Models\Application;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Services\Dns\DnsZoneService;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Show extends Component
{
    public DnsZone $zone;

    public Collection $records;

    public Collection $applications;

    public array $providerRecords = [];

    public array $edgeSettings = [];

    public string $name = '';

    public ?string $provider_zone_id = null;

    public string $api_token = '';

    public string $type = 'A';

    public string $record_name = '';

    public string $content = '';

    public int $ttl = 1;

    public bool $proxied = false;

    public ?string $comment = null;

    public ?string $application_uuid = null;

    public function mount(?string $zone_uuid = null): void
    {
        $this->zone = DnsZone::whereTeamId(currentTeam()->id)
            ->whereUuid($zone_uuid ?? request()->route('zone_uuid'))
            ->firstOrFail();

        $this->name = $this->zone->name;
        $this->provider_zone_id = $this->zone->provider_zone_id;
        $this->edgeSettings = traefikTrafficFilterSettings();

        $this->applications = Application::ownedByCurrentTeam()
            ->select('id', 'uuid', 'name', 'environment_id')
            ->get();

        $this->refreshRecords();
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'provider_zone_id' => ['nullable', 'string', 'max:255'],
            'api_token' => ['nullable', 'string'],
            'type' => ['required', 'string', Rule::in($this->supportedRecordTypes())],
            'record_name' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
            'ttl' => ['required', 'integer', 'min:1'],
            'proxied' => ['boolean'],
            'comment' => ['nullable', 'string'],
            'application_uuid' => ['nullable', 'string'],
        ];
    }

    public function saveZone(DnsZoneService $dnsZones)
    {
        $this->validateOnly('name');
        $this->validateOnly('provider_zone_id');
        $this->validateOnly('api_token');

        try {
            $this->zone = $dnsZones->updateZone($this->zone, [
                'name' => $this->name,
                'provider_zone_id' => $this->provider_zone_id,
                'api_token' => $this->api_token,
            ]);
            $this->api_token = '';

            $this->dispatch('success', 'DNS zone updated.', 'Token input was cleared and the saved token is not displayed.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function upsertRecord(DnsZoneService $dnsZones)
    {
        $this->validateOnly('type');
        $this->validateOnly('record_name');
        $this->validateOnly('content');
        $this->validateOnly('ttl');
        $this->validateOnly('proxied');
        $this->validateOnly('comment');
        $this->validateOnly('application_uuid');

        try {
            $forceDnsOnly = $this->isDnsOnlyForced($this->record_name);
            $dnsZones->upsertManagedRecord($this->zone, [
                'type' => $this->type,
                'name' => $this->record_name,
                'content' => $this->content,
                'ttl' => $this->ttl,
                'proxied' => $forceDnsOnly ? false : $this->proxied,
                'comment' => $this->comment,
                'application_uuid' => $this->application_uuid,
            ], currentTeam()->id);

            $this->resetRecordForm();
            $this->refreshRecords();

            $message = $forceDnsOnly
                ? 'DNS record saved as DNS-only because RU zones cannot use Cloudflare proxying.'
                : 'DNS record saved.';

            $this->dispatch('success', $message);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function syncProviderRecords(DnsZoneService $dnsZones)
    {
        try {
            $records = collect($dnsZones->listProviderRecords($this->zone))
                ->filter(fn (array $record) => in_array(strtoupper((string) data_get($record, 'type')), $this->supportedRecordTypes(), true));

            foreach ($records as $record) {
                $name = (string) data_get($record, 'name');
                $forceDnsOnly = $this->isDnsOnlyForced($name);

                DnsRecord::updateOrCreate(
                    [
                        'dns_zone_id' => $this->zone->id,
                        'type' => strtoupper((string) data_get($record, 'type')),
                        'name' => $name,
                    ],
                    [
                        'provider_record_id' => data_get($record, 'id'),
                        'content' => (string) data_get($record, 'content'),
                        'ttl' => max((int) data_get($record, 'ttl', 1), 1),
                        'proxied' => $forceDnsOnly ? false : (bool) data_get($record, 'proxied', false),
                        'comment' => data_get($record, 'comment'),
                        'metadata' => [
                            'provider' => 'cloudflare',
                            'dns_only_forced' => $forceDnsOnly,
                            'synced_from_provider' => true,
                        ],
                    ],
                );
            }

            $this->providerRecords = $records->values()->all();
            $this->refreshRecords();

            $this->dispatch('success', 'Cloudflare records synced.', "{$records->count()} supported record(s) imported.");
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function listProviderRecords(DnsZoneService $dnsZones)
    {
        try {
            $this->providerRecords = collect($dnsZones->listProviderRecords($this->zone))
                ->filter(fn (array $record) => in_array(strtoupper((string) data_get($record, 'type')), $this->supportedRecordTypes(), true))
                ->values()
                ->all();

            $this->dispatch('success', 'Cloudflare records loaded.', 'Review the provider list before syncing into Coolify.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function deleteRecord(string $recordUuid, DnsZoneService $dnsZones)
    {
        try {
            $record = $this->zone->records()->whereUuid($recordUuid)->firstOrFail();
            $dnsZones->deleteManagedRecord($record);
            $this->refreshRecords();

            $this->dispatch('success', 'DNS record deleted.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function isDnsOnlyForced(?string $recordName = null): bool
    {
        $hostname = $this->normalizeHostname($recordName ?: $this->zone->name);
        $zoneName = $this->normalizeHostname($this->zone->name);
        $fqdn = str_ends_with($hostname, $zoneName) ? $hostname : "{$hostname}.{$zoneName}";

        return collect(['.ru', '.xn--p1ai', '.рф'])
            ->contains(fn (string $suffix) => str_ends_with($fqdn, $suffix));
    }

    private function refreshRecords(): void
    {
        $this->records = $this->zone->records()
            ->with('application')
            ->orderBy('type')
            ->orderBy('name')
            ->get();
    }

    private function resetRecordForm(): void
    {
        $this->type = 'A';
        $this->record_name = '';
        $this->content = '';
        $this->ttl = 1;
        $this->proxied = false;
        $this->comment = null;
        $this->application_uuid = null;
    }

    private function normalizeHostname(string $hostname): string
    {
        $hostname = rtrim(trim($hostname), '.');

        return function_exists('mb_strtolower') ? mb_strtolower($hostname) : strtolower($hostname);
    }

    private function supportedRecordTypes(): array
    {
        return ['A', 'AAAA', 'CNAME', 'TXT'];
    }

    public function render()
    {
        return view('livewire.dns.show');
    }
}
