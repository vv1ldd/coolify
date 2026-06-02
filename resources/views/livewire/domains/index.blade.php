<div>
    <x-slot:title>
        Domain Bindings | Coolify
    </x-slot>

    <div class="flex flex-wrap items-center gap-2">
        <h1>Domain Bindings</h1>
    </div>
    <div class="subtitle">
        Read-only overview of domains composed from applications, services and managed DNS records.
    </div>

    <div class="pb-4">
        <x-forms.input id="search" label="Search domains" placeholder="Domain, resource, project, UUID, DNS target..."
            helper="Filters by domain, resource name, project, environment, UUID, DNS zone or DNS content." />
    </div>

    <div class="flex flex-col gap-4">
        @forelse ($entries as $entry)
            <div class="p-4 border rounded border-neutral-200 dark:border-neutral-700">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="font-mono text-lg font-semibold break-all">{{ $entry['domain'] }}</div>
                        <div class="flex flex-wrap gap-2 pt-2 text-xs">
                            @foreach ($entry['dns_zones'] as $zone)
                                <a {{ wireNavigate() }} href="{{ route('dns.show', ['zone_uuid' => $zone['uuid']]) }}"
                                    class="px-2 py-1 font-semibold rounded bg-coollabs-100 text-coollabs-700 dark:bg-coollabs-900 dark:text-coollabs-100">
                                    Zone: {{ $zone['name'] }}
                                </a>
                            @endforeach
                            <span class="px-2 py-1 rounded bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-100">
                                {{ $entry['dns_summary'] }}
                            </span>
                            <span @class([
                                'px-2 py-1 rounded font-semibold',
                                'text-neutral-700 bg-neutral-100 dark:text-neutral-100 dark:bg-neutral-800' => data_get($entry, 'edge_policy.mode') === 'off',
                                'text-green-800 bg-green-100 dark:text-green-100 dark:bg-green-800' => data_get($entry, 'edge_policy.mode') === 'normal',
                                'text-amber-800 bg-amber-100 dark:text-amber-100 dark:bg-amber-800' => data_get($entry, 'edge_policy.mode') === 'strict',
                                'text-red-800 bg-red-100 dark:text-red-100 dark:bg-red-800' => data_get($entry, 'edge_policy.mode') === 'under_attack',
                            ])>
                                EdgePolicy: {{ str_replace('_', ' ', data_get($entry, 'edge_policy.mode', 'off')) }}
                            </span>
                            @if (data_get($entry, 'edge_policy.source') === 'policy')
                                <span class="px-2 py-1 rounded bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-100">
                                    {{ data_get($entry, 'edge_policy.name') }}
                                </span>
                            @endif
                            @if (data_get($entry, 'dns_steering'))
                                <span @class([
                                    'px-2 py-1 rounded font-semibold',
                                    'text-green-800 bg-green-100 dark:text-green-100 dark:bg-green-800' => data_get($entry, 'dns_steering.enabled'),
                                    'text-neutral-700 bg-neutral-100 dark:text-neutral-100 dark:bg-neutral-800' => ! data_get($entry, 'dns_steering.enabled'),
                                ])>
                                    DNS Steering: {{ data_get($entry, 'dns_steering.enabled') ? 'enabled' : 'disabled' }}
                                </span>
                                <span class="px-2 py-1 rounded bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-100">
                                    {{ str_replace('_', ' ', data_get($entry, 'dns_steering.strategy')) }}
                                </span>
                            @else
                                <span class="px-2 py-1 rounded bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-100">
                                    DNS steering not configured
                                </span>
                            @endif
                            @if ($entry['has_conflict'])
                                <span class="px-2 py-1 font-semibold text-red-800 bg-red-100 rounded dark:text-red-100 dark:bg-red-800">
                                    Conflict: {{ $entry['conflict_summary'] }}
                                </span>
                            @endif
                            @if ($entry['dns_unexpected'])
                                <span class="px-2 py-1 font-semibold text-amber-800 bg-amber-100 rounded dark:text-amber-100 dark:bg-amber-800">
                                    DNS target differs from resource server
                                </span>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="grid gap-4 pt-4 xl:grid-cols-2">
                    <div>
                        <h3 class="pb-2">Used By</h3>
                        <div class="flex flex-col gap-2">
                            @forelse ($entry['uses'] as $use)
                                <div class="p-3 border rounded border-neutral-100 dark:border-neutral-800">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="px-2 py-1 text-xs font-semibold rounded bg-cyan-100 text-cyan-800 dark:bg-cyan-900 dark:text-cyan-100">
                                            {{ $use['source'] }}
                                        </span>
                                        <span class="px-2 py-1 text-xs rounded bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-100">
                                            {{ $use['resource_type'] }}
                                        </span>
                                        @if (data_get($use, 'edge.active'))
                                            <span class="px-2 py-1 text-xs font-semibold text-green-800 bg-green-100 rounded dark:text-green-100 dark:bg-green-800">
                                                Edge active: {{ data_get($use, 'edge.summary') }}
                                            </span>
                                        @else
                                            <span class="px-2 py-1 text-xs rounded bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-100">
                                                Edge {{ data_get($use, 'edge.summary') }}
                                            </span>
                                        @endif
                                    </div>
                                    <div class="pt-2">
                                        @if ($use['resource_link'])
                                            <a {{ wireNavigate() }} class="font-semibold underline" href="{{ $use['resource_link'] }}">
                                                {{ $use['resource_name'] }}
                                            </a>
                                        @else
                                            <span class="font-semibold">{{ $use['resource_name'] }}</span>
                                        @endif
                                        <span class="text-xs text-neutral-500">({{ $use['resource_uuid'] }})</span>
                                    </div>
                                    <div class="pt-1 text-sm text-neutral-500">
                                        {{ $use['project_name'] ?: 'No project' }} / {{ $use['environment_name'] ?: 'No environment' }}
                                        @if ($use['service_name'])
                                            · service {{ $use['service_name'] }}
                                        @endif
                                        @if ($use['container_name'])
                                            · container {{ $use['container_name'] }}
                                        @endif
                                    </div>
                                    @if ($use['raw_domain'] !== $entry['domain'])
                                        <div class="pt-1 font-mono text-xs text-neutral-500 break-all">
                                            {{ $use['raw_domain'] }}
                                        </div>
                                    @endif
                                    @if (count($entry['node_candidates']) > 0)
                                        <div class="pt-2 text-xs text-neutral-500">
                                            Future DNS steering candidates:
                                            @foreach ($entry['node_candidates']->take(4) as $candidate)
                                                <span class="font-mono">{{ data_get($candidate, 'ip') }}</span>@if (! $loop->last), @endif
                                            @endforeach
                                            @if ($entry['node_candidates']->count() > 4)
                                                +{{ $entry['node_candidates']->count() - 4 }} more
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            @empty
                                <div class="p-3 text-sm border rounded text-neutral-500 border-neutral-100 dark:border-neutral-800">
                                    No application or service usage detected. This domain only appears in managed DNS records.
                                </div>
                            @endforelse
                        </div>
                    </div>

                    <div>
                        <h3 class="pb-2">Managed DNS</h3>
                        <div class="flex flex-col gap-2">
                            @forelse ($entry['dns_records'] as $record)
                                <div class="p-3 border rounded border-neutral-100 dark:border-neutral-800">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="px-2 py-1 font-mono text-xs rounded bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-100">
                                            {{ $record['type'] }}
                                        </span>
                                        <span @class([
                                            'px-2 py-1 text-xs font-semibold rounded',
                                            'text-green-800 bg-green-100 dark:text-green-100 dark:bg-green-800' => $record['proxied'],
                                            'text-neutral-700 bg-neutral-100 dark:text-neutral-100 dark:bg-neutral-800' => ! $record['proxied'],
                                        ])>
                                            {{ $record['proxy_status'] }}
                                        </span>
                                        <span class="px-2 py-1 text-xs rounded bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-100">
                                            TTL {{ $record['ttl'] }}
                                        </span>
                                    </div>
                                    <div class="pt-2 text-sm">
                                        <span class="font-semibold">{{ $record['zone_name'] }}</span>
                                        <span class="text-neutral-500">/ {{ $record['name'] }}</span>
                                    </div>
                                    <div class="pt-1 font-mono text-xs break-all text-neutral-500">{{ $record['content'] }}</div>
                                    @if ($record['application_name'])
                                        <div class="pt-1 text-xs text-neutral-500">
                                            Associated app:
                                            <span class="font-semibold">{{ $record['application_name'] }}</span>
                                            @if ($record['application_uuid_short'])
                                                ({{ $record['application_uuid_short'] }})
                                            @endif
                                            @if ($record['project_name'] || $record['environment_name'])
                                                · {{ $record['project_name'] ?: 'No project' }} / {{ $record['environment_name'] ?: 'No environment' }}
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            @empty
                                <div class="p-3 text-sm border rounded text-neutral-500 border-neutral-100 dark:border-neutral-800">
                                    No managed DNS record found for this domain.
                                </div>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="p-4 border rounded border-neutral-200 dark:border-neutral-700">
                No domains found.
            </div>
        @endforelse
    </div>
</div>
