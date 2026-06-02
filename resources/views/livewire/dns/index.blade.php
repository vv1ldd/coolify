<div>
    <x-slot:title>
        DNS & Edge Control Plane | Coolify
    </x-slot>

    <div class="flex items-center gap-2">
        <h1>DNS & Edge Control Plane</h1>
    </div>
    <div class="subtitle">Domains define intent. Projections prepare adapter state. Cloudflare, Traefik and future authoritative DNS adapters execute that state.</div>

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="p-4 border rounded h-fit border-neutral-200 dark:border-neutral-700">
            <h2>Adapter Status</h2>
            <div class="pt-2 text-sm text-neutral-500">
                Cloudflare access is prepared during install. Edge capabilities come from online control-plane peers.
            </div>
            <div class="flex justify-between gap-3 pt-4 text-sm">
                <span>Token</span>
                <span class="font-mono">{{ $tokenConfigured ? 'configured' : 'missing' }}</span>
            </div>
            <div class="flex justify-between gap-3 pt-2 text-sm">
                <span>Last validated</span>
                <span class="font-mono">{{ $lastValidatedAt ?: 'never' }}</span>
            </div>
            <div class="pt-4 text-xs font-bold uppercase text-neutral-500">Online edge peers</div>
            <div class="pt-1 font-mono text-sm">{{ data_get($edgeNodes, 'online_peers', 0) }}</div>
            <div class="grid grid-cols-2 gap-2 pt-3 text-xs">
                @foreach (data_get($edgeNodes, 'capabilities', []) as $capability => $count)
                    <div class="p-2 border rounded border-neutral-200 dark:border-neutral-700">
                        <div class="font-mono text-[10px] text-neutral-500">{{ $capability }}</div>
                        <div class="font-bold text-black dark:text-white">{{ $count }}</div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="p-4 border rounded border-neutral-200 dark:border-neutral-700 xl:col-span-2">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2>Project & Service Domains</h2>
                @if (Route::has('domains.index'))
                    <a {{ wireNavigate() }} href="{{ route('domains.index') }}" class="text-xs font-bold underline">
                        Open full inventory
                    </a>
                @endif
            </div>
            <div class="pb-4 text-sm text-neutral-500">
                Use project and service domain settings to attach domains, choose matching Cloudflare zones, and control DNS proxying for each deployed resource.
            </div>

            <div class="flex flex-col gap-3 text-sm">
                @forelse ($domainEntries as $entry)
                    <div class="p-3 border rounded border-neutral-200 dark:border-neutral-700">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="font-mono font-bold text-black break-all dark:text-white">{{ data_get($entry, 'domain') }}</div>
                                <div class="pt-1 text-xs text-neutral-500">
                                    {{ collect(data_get($entry, 'uses', []))->pluck('resource_name')->filter()->take(2)->join(', ') ?: 'No resource name' }}
                                    @if (data_get($entry, 'has_conflict'))
                                        · conflict
                                    @endif
                                </div>
                            </div>
                            <span class="shrink-0 px-2 py-1 text-xs rounded bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-100">
                                {{ data_get($entry, 'dns_summary') }}
                            </span>
                            <span class="shrink-0 px-2 py-1 text-xs rounded bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-100">
                                {{ data_get($entry, 'adapter_mode') }}
                            </span>
                        </div>
                        <div class="flex flex-wrap gap-2 pt-2 text-xs">
                            @forelse (data_get($entry, 'dns_zones', []) as $zone)
                                <a {{ wireNavigate() }} href="{{ route('dns.show', ['zone_uuid' => $zone['uuid']]) }}"
                                    class="px-2 py-1 font-semibold rounded bg-coollabs-100 text-coollabs-700 dark:bg-coollabs-900 dark:text-coollabs-100">
                                    Zone: {{ $zone['name'] }}
                                </a>
                            @empty
                                <span class="px-2 py-1 rounded bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-100">
                                    No matching zone
                                </span>
                            @endforelse
                            @foreach (collect(data_get($entry, 'uses', []))->take(2) as $use)
                                @if (data_get($use, 'resource_link'))
                                    <a {{ wireNavigate() }} href="{{ data_get($use, 'resource_link') }}" class="px-2 py-1 rounded bg-neutral-100 text-neutral-700 underline dark:bg-neutral-800 dark:text-neutral-100">
                                        Manage {{ data_get($use, 'source') }}
                                    </a>
                                @endif
                            @endforeach
                            @if (data_get($entry, 'projection'))
                                <span class="px-2 py-1 rounded bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-100">
                                    Projection: {{ data_get($entry, 'projection.adapter') }} v{{ data_get($entry, 'projection.version') }} · {{ data_get($entry, 'projection.status') }}
                                </span>
                                <span class="px-2 py-1 rounded bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-100">
                                    Drift: {{ data_get($entry, 'projection.drift_state') }}
                                </span>
                                <span class="px-2 py-1 font-mono rounded bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-100">
                                    {{ data_get($entry, 'projection.projection_short') }}
                                </span>
                            @else
                                <span class="px-2 py-1 rounded bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-100">
                                    Projection not generated
                                </span>
                            @endif
                            @if (data_get($entry, 'last_control_action'))
                                <span class="px-2 py-1 rounded bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-100">
                                    Last action: {{ data_get($entry, 'last_control_action.adapter') }} · {{ data_get($entry, 'last_control_action.status') }}
                                </span>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="p-3 text-sm border rounded text-neutral-500 border-neutral-200 dark:border-neutral-700">
                        No project or service domains detected yet.
                    </div>
                @endforelse
            </div>
        </div>

        <div class="p-4 border rounded border-neutral-200 dark:border-neutral-700 xl:col-span-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2>Simple L1 Domain</h2>
                @if (data_get($simpleL1, 'zone_uuid'))
                    <a {{ wireNavigate() }} href="{{ route('dns.show', ['zone_uuid' => data_get($simpleL1, 'zone_uuid')]) }}" class="text-xs font-bold underline">
                        Manage zone
                    </a>
                @endif
            </div>
            <div class="grid gap-3 pt-3 text-sm md:grid-cols-4">
                <div class="p-3 border rounded border-neutral-200 dark:border-neutral-700">
                    <div class="text-xs font-bold uppercase text-neutral-500">Domain</div>
                    <div class="pt-1 font-mono font-bold text-black break-all dark:text-white">{{ data_get($simpleL1, 'domain') }}</div>
                </div>
                <div class="p-3 border rounded border-neutral-200 dark:border-neutral-700">
                    <div class="text-xs font-bold uppercase text-neutral-500">Current Target</div>
                    <div class="pt-1 font-mono font-bold text-black break-all dark:text-white">{{ data_get($simpleL1, 'current_target') ?: 'not recorded' }}</div>
                </div>
                <div class="p-3 border rounded border-neutral-200 dark:border-neutral-700">
                    <div class="text-xs font-bold uppercase text-neutral-500">Steering</div>
                    <div class="pt-1 font-bold text-black dark:text-white">
                        {{ data_get($simpleL1, 'policy_enabled') ? 'enabled' : 'disabled' }}
                        @if (data_get($simpleL1, 'strategy'))
                            · {{ str_replace('_', ' ', data_get($simpleL1, 'strategy')) }}
                        @endif
                    </div>
                </div>
                <div class="p-3 border rounded border-neutral-200 dark:border-neutral-700">
                    <div class="text-xs font-bold uppercase text-neutral-500">Zone / Adapter</div>
                    <div class="pt-1 font-bold text-black break-all dark:text-white">{{ data_get($simpleL1, 'zone_name') ?: 'not matched' }}</div>
                    <div class="pt-1 text-xs text-neutral-500">{{ data_get($simpleL1, 'adapter_mode', 'unmatched') }} · {{ data_get($simpleL1, 'candidate_count', 0) }} failover candidate(s)</div>
                </div>
            </div>
            <div class="grid gap-3 pt-3 text-sm md:grid-cols-2">
                <div class="p-3 border rounded border-neutral-200 dark:border-neutral-700">
                    <div class="text-xs font-bold uppercase text-neutral-500">Latest Projection</div>
                    @if (data_get($simpleL1, 'projection'))
                        <div class="pt-1 font-bold text-black dark:text-white">
                            {{ data_get($simpleL1, 'projection.adapter') }} v{{ data_get($simpleL1, 'projection.version') }} · {{ data_get($simpleL1, 'projection.status') }}
                        </div>
                        <div class="pt-1 font-mono text-xs text-neutral-500">{{ data_get($simpleL1, 'projection.projection_short') }}</div>
                        <div class="pt-1 text-xs text-neutral-500">
                            Drift: {{ data_get($simpleL1, 'projection.drift_state') }} · Quorum: {{ data_get($simpleL1, 'projection.observation_quorum_status', 'unknown') }}
                        </div>
                    @else
                        <div class="pt-1 text-neutral-500">Projection not generated</div>
                    @endif
                </div>
                <div class="p-3 border rounded border-neutral-200 dark:border-neutral-700">
                    <div class="text-xs font-bold uppercase text-neutral-500">Latest Control Action</div>
                    @if (data_get($simpleL1, 'last_control_action'))
                        <div class="pt-1 font-bold text-black dark:text-white">
                            {{ data_get($simpleL1, 'last_control_action.adapter') }} · {{ data_get($simpleL1, 'last_control_action.status') }}
                        </div>
                        <div class="pt-1 text-xs text-neutral-500">{{ data_get($simpleL1, 'last_control_action.action_type') }} · {{ data_get($simpleL1, 'last_control_action.executed_at') }}</div>
                    @else
                        <div class="pt-1 text-neutral-500">No adapter execution recorded yet.</div>
                    @endif
                </div>
            </div>
            @if (data_get($simpleL1, 'message'))
                <div class="pt-3 text-xs text-neutral-500">{{ data_get($simpleL1, 'message') }}</div>
            @endif
        </div>

        <div class="p-4 border rounded border-neutral-200 dark:border-neutral-700 xl:col-span-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2>Resource Continuity</h2>
                <span class="text-xs font-bold uppercase text-neutral-500">L7-first routing continuity</span>
            </div>
            <div class="pt-2 text-sm text-neutral-500">
                Simple L1, marketplace, APIs, checkout and provider gateways share the same continuity grammar: resource intent, projection, observation, arbitration and control action.
            </div>
            <div class="grid gap-3 pt-3 text-sm md:grid-cols-2 xl:grid-cols-3">
                @forelse (data_get($resourceContinuity, 'policies', []) as $policy)
                    <div class="p-3 border rounded border-neutral-200 dark:border-neutral-700">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="font-bold text-black dark:text-white">{{ data_get($policy, 'resource_type') }}</div>
                                <div class="pt-1 font-mono text-xs text-neutral-500 break-all">{{ data_get($policy, 'domain') ?: data_get($policy, 'resource_uuid') }}</div>
                            </div>
                            <span class="px-2 py-1 text-xs rounded bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-100">
                                {{ strtoupper(data_get($policy, 'routing_layer', 'l7')) }}
                            </span>
                        </div>
                        <div class="flex flex-wrap gap-2 pt-2 text-xs">
                            <span class="px-2 py-1 rounded bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-100">
                                {{ data_get($policy, 'candidate_count', 0) }} candidate(s)
                            </span>
                            @if (data_get($policy, 'assessment_hash'))
                                <span class="px-2 py-1 font-mono rounded bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-100">
                                    assessment={{ data_get($policy, 'assessment_hash') }}
                                </span>
                                <span class="px-2 py-1 rounded bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-100">
                                    severity {{ data_get($policy, 'assessment_severity', 0) }}
                                </span>
                            @endif
                            <span class="px-2 py-1 rounded bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-100">
                                {{ data_get($policy, 'decision') ?: 'no arbitration yet' }}
                            </span>
                            @if (data_get($policy, 'decision_hash'))
                                <span class="px-2 py-1 font-mono rounded bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-100">
                                    decision={{ data_get($policy, 'decision_hash') }}
                                </span>
                            @endif
                            @if (data_get($policy, 'authority_basis'))
                                <span class="px-2 py-1 rounded bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-100">
                                    authority={{ data_get($policy, 'authority_basis') }}
                                </span>
                            @endif
                            @if (data_get($policy, 'reason'))
                                <span class="px-2 py-1 rounded bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-100">
                                    {{ data_get($policy, 'reason') }}
                                </span>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="p-3 text-sm border rounded text-neutral-500 border-neutral-200 dark:border-neutral-700 md:col-span-2 xl:col-span-3">
                        No generic resource routing policies configured yet. Simple L1 failover remains available through its specialized path.
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</div>
