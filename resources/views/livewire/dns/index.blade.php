<div>
    <x-slot:title>
        DNS Zones | Coolify
    </x-slot>

    <div class="flex items-center gap-2">
        <h1>DNS Zones</h1>
    </div>
    <div class="subtitle">Team-scoped DNS provider configuration and managed records.</div>

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="xl:col-span-2">
            <div class="flex flex-col gap-3">
                @forelse ($zones as $zone)
                    <a {{ wireNavigate() }} href="{{ route('dns.show', ['zone_uuid' => $zone->uuid]) }}"
                        class="gap-2 border cursor-pointer coolbox group">
                        <div class="flex flex-col justify-center mx-6">
                            <div class="box-title">{{ $zone->name }}</div>
                            <div class="box-description">
                                Cloudflare zone {{ $zone->provider_zone_id ?: 'not linked' }} · {{ $zone->records_count }} managed record(s)
                            </div>
                            @if (str($zone->name)->endsWith(['.ru', '.xn--p1ai', '.рф']))
                                <span class="w-fit px-2 py-1 mt-2 text-xs font-semibold text-amber-800 bg-amber-100 rounded dark:text-amber-100 dark:bg-amber-800">
                                    Cloudflare proxy forced DNS-only
                                </span>
                            @endif
                        </div>
                    </a>
                @empty
                    <div class="p-4 border rounded border-neutral-200 dark:border-neutral-700">
                        No DNS zones found.
                    </div>
                @endforelse
            </div>
        </div>

        <div class="p-4 border rounded border-neutral-200 dark:border-neutral-700">
            <div class="flex items-center gap-2">
                <h2>Create DNS Zone</h2>
            </div>
            <div class="pb-4 text-sm text-neutral-500">
                Paste a Cloudflare token, load available zones, then select the zone to manage. The token is stored encrypted and will not be shown after saving.
            </div>

            <form class="flex flex-col gap-3" wire:submit="createZone">
                <x-forms.select id="provider" label="Provider" required>
                    <option value="cloudflare">Cloudflare</option>
                </x-forms.select>
                <x-forms.input id="api_token" type="password" label="Cloudflare API token" required
                    helper="Use a scoped token with Zone:Read and DNS:Edit permissions." />
                <x-forms.button type="button" wire:click="loadProviderZones">Load zones from token</x-forms.button>

                @if (count($providerZones) > 0)
                    <x-forms.select id="selectedProviderZoneId" label="Available Cloudflare zones"
                        helper="Selecting a zone fills the zone name and Cloudflare Zone ID.">
                        <option value="">Select a zone...</option>
                        @foreach ($providerZones as $zone)
                            <option value="{{ $zone['id'] }}">{{ $zone['name'] }}{{ $zone['status'] ? ' · '.$zone['status'] : '' }}</option>
                        @endforeach
                    </x-forms.select>
                @endif

                <x-forms.input id="name" label="Zone name" placeholder="example.com" required />
                <x-forms.input id="provider_zone_id" label="Cloudflare Zone ID"
                    helper="Filled automatically after loading zones. Manual entry remains available as a fallback." />

                <x-forms.button type="submit">Create Zone</x-forms.button>
            </form>
        </div>
    </div>
</div>
