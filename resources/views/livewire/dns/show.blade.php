<div>
    <x-slot:title>
        {{ data_get_str($zone, 'name')->limit(20) }} > DNS | Coolify
    </x-slot>

    <div class="flex flex-wrap items-center gap-2">
        <h1>DNS Zone</h1>
        <a {{ wireNavigate() }} class="text-sm underline" href="{{ route('dns.index') }}">Back to DNS Zones</a>
    </div>
    <div class="subtitle">{{ $zone->name }}</div>

    @if ($this->isDnsOnlyForced())
        <div class="p-4 mb-4 text-sm border rounded border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100">
            This RU zone is DNS-only at Cloudflare by policy. The Cloudflare proxied toggle is disabled and records are saved with proxied=false, while Coolify Edge Protection can still run at the Traefik layer.
        </div>
    @endif

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="flex flex-col gap-6 xl:col-span-2">
            <div class="p-4 border rounded border-neutral-200 dark:border-neutral-700">
                <div class="flex items-center gap-2">
                    <h2>Cloudflare Zone</h2>
                    <x-forms.button type="submit" form="dns-zone-form">Save</x-forms.button>
                </div>
                <div class="pb-4 text-sm text-neutral-500">
                    Update zone metadata. Leave the token blank to keep the existing encrypted token.
                </div>

                <form id="dns-zone-form" class="flex flex-col gap-3" wire:submit="saveZone">
                    <div class="flex flex-col gap-2 xl:flex-row">
                        <x-forms.input id="name" label="Zone name" required />
                        <x-forms.input id="provider_zone_id" label="Cloudflare Zone ID" />
                    </div>
                    <x-forms.input id="api_token" type="password" label="Replace Cloudflare API token"
                        placeholder="Leave blank to keep existing token" />
                </form>
            </div>

            <div class="p-4 border rounded border-neutral-200 dark:border-neutral-700">
                <div class="flex flex-wrap items-center gap-2">
                    <h2>DNS Records</h2>
                    <x-forms.button wire:click="listProviderRecords">List Cloudflare Records</x-forms.button>
                    <x-forms.button wire:click="syncProviderRecords">Sync from Cloudflare</x-forms.button>
                </div>
                <div class="pb-4 text-sm text-neutral-500">
                    Coolify manages DNS records here. Optional application association is composition-only and does not transfer ownership from DNS Zones to Applications.
                </div>

                <form class="flex flex-col gap-3 pb-6" wire:submit="upsertRecord">
                    <div class="grid gap-3 lg:grid-cols-5">
                        <x-forms.select id="type" label="Type" required>
                            <option value="A">A</option>
                            <option value="CNAME">CNAME</option>
                            <option value="TXT">TXT</option>
                        </x-forms.select>
                        <x-forms.input id="record_name" label="Name" placeholder="app or app.example.com" required />
                        <x-forms.input id="content" label="Content" placeholder="203.0.113.10 or target.example.net" required />
                        <x-forms.input id="ttl" type="number" min="1" label="TTL" required
                            helper="Use 1 for Cloudflare automatic TTL." />
                        <div class="flex items-end">
                            <x-forms.checkbox id="proxied" label="Cloudflare proxied"
                                :disabled="$this->isDnsOnlyForced($record_name)" />
                        </div>
                    </div>
                    <div class="grid gap-3 lg:grid-cols-2">
                        <x-forms.select id="application_uuid" label="Application association"
                            helper="Composition only: the app is linked for context, not ownership.">
                            <option value="">None</option>
                            @foreach ($applications as $application)
                                <option value="{{ $application->uuid }}">{{ $application->name }}</option>
                            @endforeach
                        </x-forms.select>
                        <x-forms.input id="comment" label="Comment" />
                    </div>
                    @if ($this->isDnsOnlyForced($record_name))
                        <div class="text-xs text-amber-600 dark:text-amber-400">
                            RU domain detected for this record. Cloudflare proxy will be overridden to DNS-only on save.
                        </div>
                    @endif
                    <x-forms.button type="submit">Upsert Record</x-forms.button>
                </form>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left border-b border-neutral-200 dark:border-neutral-700">
                                <th class="py-2 pr-4">Type</th>
                                <th class="py-2 pr-4">Name</th>
                                <th class="py-2 pr-4">Content</th>
                                <th class="py-2 pr-4">TTL</th>
                                <th class="py-2 pr-4">Proxy</th>
                                <th class="py-2 pr-4">Application</th>
                                <th class="py-2 pr-4"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($records as $record)
                                <tr class="border-b border-neutral-100 dark:border-neutral-800">
                                    <td class="py-2 pr-4 font-mono">{{ $record->type }}</td>
                                    <td class="py-2 pr-4 break-all">{{ $record->name }}</td>
                                    <td class="py-2 pr-4 break-all">{{ $record->content }}</td>
                                    <td class="py-2 pr-4">{{ $record->ttl }}</td>
                                    <td class="py-2 pr-4">
                                        @if ($this->isDnsOnlyForced($record->name) || data_get($record->metadata, 'dns_only_forced'))
                                            <span class="px-2 py-1 text-xs font-semibold text-amber-800 bg-amber-100 rounded dark:text-amber-100 dark:bg-amber-800">
                                                DNS-only forced
                                            </span>
                                        @elseif ($record->proxied)
                                            <span class="px-2 py-1 text-xs font-semibold text-green-800 bg-green-100 rounded dark:text-green-100 dark:bg-green-800">
                                                Proxied
                                            </span>
                                        @else
                                            <span class="px-2 py-1 text-xs font-semibold text-neutral-700 bg-neutral-100 rounded dark:text-neutral-100 dark:bg-neutral-800">
                                                DNS-only
                                            </span>
                                        @endif
                                    </td>
                                    <td class="py-2 pr-4">{{ $record->application?->name ?: 'None' }}</td>
                                    <td class="py-2 pr-4 text-right">
                                        <x-forms.button wire:click="deleteRecord('{{ $record->uuid }}')">Delete</x-forms.button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td class="py-3 text-neutral-500" colspan="7">No managed DNS records yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            @if (count($providerRecords) > 0)
                <div class="p-4 border rounded border-neutral-200 dark:border-neutral-700">
                    <h3>Cloudflare Records Preview</h3>
                    <div class="pb-3 text-sm text-neutral-500">
                        Last provider list response. Sync imports supported A, CNAME and TXT records into Coolify.
                    </div>
                    <div class="grid gap-2">
                        @foreach ($providerRecords as $providerRecord)
                            <div class="p-3 border rounded border-neutral-200 dark:border-neutral-800">
                                <div class="font-mono text-xs">{{ data_get($providerRecord, 'type') }} {{ data_get($providerRecord, 'name') }}</div>
                                <div class="break-all">{{ data_get($providerRecord, 'content') }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        <div class="p-4 border rounded h-fit border-neutral-200 dark:border-neutral-700">
            <h2>Edge Protection</h2>
            <div class="pb-4 text-sm text-neutral-500">
                Edge Protection is separate from DNS proxying. Cloudflare proxied controls DNS provider behavior; Coolify Edge Protection is generated as Traefik labels for application routers, with an optional stateful browser challenge layer for application traffic.
            </div>

            <div class="grid gap-2 text-sm">
                <x-edge-policy-summary :domains="$zone->name" :teamId="$zone->team_id" />

                <div class="flex justify-between gap-3">
                    <span>Generated labels</span>
                    <span class="font-mono">{{ data_get($edgeSettings, 'enabled', true) ? 'enabled' : 'disabled' }}</span>
                </div>
                <div class="flex justify-between gap-3">
                    <span>Rate limit</span>
                    <span class="font-mono">{{ data_get($edgeSettings, 'rate_limit_average', 0) }}/s burst {{ data_get($edgeSettings, 'rate_limit_burst', 0) }}</span>
                </div>
                <div class="flex justify-between gap-3">
                    <span>Inflight requests</span>
                    <span class="font-mono">{{ data_get($edgeSettings, 'in_flight_request_limit', 0) }}</span>
                </div>
                <div class="flex justify-between gap-3">
                    <span>Security headers</span>
                    <span class="font-mono">{{ data_get($edgeSettings, 'security_headers_enabled', true) ? 'on' : 'off' }}</span>
                </div>
                <div class="flex justify-between gap-3">
                    <span>Bad user agents</span>
                    <span class="font-mono">{{ data_get($edgeSettings, 'user_agent_filter_enabled', true) ? 'on' : 'off' }}</span>
                </div>
                <div class="flex justify-between gap-3">
                    <span>Probe paths</span>
                    <span class="font-mono">{{ data_get($edgeSettings, 'probe_path_filter_enabled', true) ? 'on' : 'off' }}</span>
                </div>
            </div>

            <div class="pt-4 text-xs text-neutral-500">
                DNS provider settings stay provider-scoped. Domain protection is resolved from EdgePolicy and should be changed from the relevant project/resource domain settings, not from this DNS provider panel.
            </div>
        </div>
    </div>
</div>
