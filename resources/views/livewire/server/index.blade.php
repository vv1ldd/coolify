<div>
    <x-slot:title>
        L1 Network Nodes | Sovereign
    </x-slot>

    <!-- Header Section -->
    <div class="card-neo mb-6 p-6 relative overflow-hidden flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 bg-gradient-to-br dark:from-[rgba(168,85,247,0.12)] dark:via-[#0d0718] dark:to-[rgba(34,211,238,0.06)] bg-[#0d0718] border-[3px] border-black shadow-[4px_4px_0px_#000000] rounded-lg">
        <div>
            <div class="flex gap-2 mb-3">
                <span class="px-2 py-0.5 text-[9px] font-black uppercase tracking-wider rounded border border-black text-[#c084fc] bg-[#a855f7]/10 shadow-[1.5px_1.5px_0px_#000000]">
                    CONSENSUS LAYER
                </span>
                <span class="px-2 py-0.5 text-[9px] font-black uppercase tracking-wider rounded border border-black text-[#22d3ee] bg-[#22d3ee]/10 shadow-[1.5px_1.5px_0px_#000000]">
                    TOPOLOGY: {{ $servers->count() }} NODES
                </span>
            </div>
            <h2 class="text-2xl font-black text-black dark:text-white m-0 tracking-tight" style="font-family: 'Space Grotesk', sans-serif;">
                L1 NETWORK TOPOLOGY
            </h2>
            <p class="text-neutral-600 dark:text-neutral-400 mt-1.5 text-xs font-semibold max-w-2xl leading-relaxed">
                Manage, shield, and orchestrate your distributed consensus servers. Nodes are securely verified and cryptographically connected to the Simple L1 network.
            </p>
        </div>
        <div class="flex flex-col gap-1.5 shrink-0">
            @can('createAnyResource')
                <x-modal-input buttonTitle="+ PROVISION NEW NODE" title="Provision Sovereign Node" :closeOutside="false">
                    <livewire:server.create />
                </x-modal-input>
            @endcan
        </div>
    </div>

    <!-- SL1 Federation Fabric -->
    <div class="card-neo mb-6 p-5 bg-white dark:bg-[#090909] border-[3px] border-black shadow-[4px_4px_0px_#000000] rounded-lg overflow-hidden">
        <div class="flex flex-col xl:flex-row xl:items-start justify-between gap-5">
            <div class="min-w-0">
                <div class="flex flex-wrap gap-2 mb-3">
                    <span class="px-2 py-0.5 text-[9px] font-black uppercase tracking-wider rounded border border-black text-[#22d3ee] bg-[#22d3ee]/10 shadow-[1.5px_1.5px_0px_#000000]">
                        SL1 FEDERATION FABRIC
                    </span>
                    <span class="px-2 py-0.5 text-[9px] font-black uppercase tracking-wider rounded border border-black text-[#c084fc] bg-[#a855f7]/10 shadow-[1.5px_1.5px_0px_#000000]">
                        POLICY: {{ strtoupper(data_get($sl1Network, 'policy_mode', 'observe_only')) }}
                    </span>
                    <span class="px-2 py-0.5 text-[9px] font-black uppercase tracking-wider rounded border border-black text-[#fbbf24] bg-[#f59e0b]/10 shadow-[1.5px_1.5px_0px_#000000]">
                        PROJECTION: {{ data_get($sl1Network, 'projection_allowed') ? 'ENABLED' : 'LOCKED' }}
                    </span>
                </div>
                <h3 class="text-lg font-black text-black dark:text-white m-0 tracking-tight" style="font-family: 'Space Grotesk', sans-serif;">
                    Sovereign Authority Mesh
                </h3>
                <p class="text-neutral-600 dark:text-neutral-400 mt-1.5 text-xs font-semibold max-w-3xl leading-relaxed">
                    Mesh truth is split by boundary: bridge discovery is evidence, host admission is membership, and this UI is a read-only projection.
                </p>
            </div>

            @if (data_get($sl1Network, 'available'))
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 shrink-0 w-full xl:w-auto">
                    <div class="rounded border-2 border-black bg-neutral-50 dark:bg-neutral-900/60 p-3 shadow-[2px_2px_0px_#000000]">
                        <div class="text-[9px] font-black uppercase tracking-widest text-neutral-500">Admitted</div>
                        <div class="text-xl font-black text-black dark:text-white">{{ data_get($sl1Network, 'summary.peers', 0) }}</div>
                    </div>
                    <div class="rounded border-2 border-black bg-[#22d3ee]/10 p-3 shadow-[2px_2px_0px_#000000]">
                        <div class="text-[9px] font-black uppercase tracking-widest text-[#22d3ee]">Verified</div>
                        <div class="text-xl font-black text-black dark:text-white">{{ data_get($sl1Network, 'summary.verified', 0) }}</div>
                    </div>
                    <div class="rounded border-2 border-black bg-[#a855f7]/10 p-3 shadow-[2px_2px_0px_#000000]">
                        <div class="text-[9px] font-black uppercase tracking-widest text-[#c084fc]">Evidence</div>
                        <div class="text-xl font-black text-black dark:text-white">{{ data_get($sl1Network, 'summary.observed_events', 0) }}</div>
                    </div>
                    <div class="rounded border-2 border-black bg-[#f59e0b]/10 p-3 shadow-[2px_2px_0px_#000000]">
                        <div class="text-[9px] font-black uppercase tracking-widest text-[#fbbf24]">Discovery</div>
                        <div class="text-xl font-black text-black dark:text-white">{{ data_get($sl1Network, 'summary.discovery_candidates', 0) }}</div>
                    </div>
                </div>
            @endif
        </div>

        @if (! data_get($sl1Network, 'available'))
            <div class="mt-4 rounded border-2 border-dashed border-black bg-neutral-50 dark:bg-neutral-900/40 p-4 text-xs font-bold text-neutral-500 dark:text-neutral-400">
                {{ data_get($sl1Network, 'reason', 'SL1 federation metadata is not available yet.') }}
            </div>
        @else
            <div class="mt-5 grid gap-4 xl:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)]">
                <div class="rounded border-2 border-black bg-neutral-50 dark:bg-neutral-900/50 p-4 shadow-[2px_2px_0px_#000000]">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <div class="text-[9px] font-black uppercase tracking-widest text-neutral-500">Local Runtime</div>
                            <div class="mt-1 text-sm font-black text-black dark:text-white truncate">
                                {{ data_get($sl1Network, 'local.issuer') }}
                            </div>
                        </div>
                        <span class="px-2 py-0.5 text-[9px] font-black uppercase tracking-wider rounded border border-black text-[#22d3ee] bg-[#22d3ee]/10">
                            {{ strtoupper(data_get($sl1Network, 'local.status', 'unknown')) }}
                        </span>
                    </div>
                    <div class="mt-3 grid gap-2 text-[10px] font-bold uppercase tracking-widest text-neutral-500 dark:text-neutral-400">
                        <div class="flex justify-between gap-3">
                            <span>Node ID</span>
                            <span class="text-right text-black dark:text-white normal-case tracking-normal truncate">{{ data_get($sl1Network, 'local.node_id') ?: 'not published yet' }}</span>
                        </div>
                        <div class="flex justify-between gap-3">
                            <span>Algorithm</span>
                            <span class="text-black dark:text-white">{{ data_get($sl1Network, 'local.algorithm', 'ed25519') }}</span>
                        </div>
                        <div class="flex justify-between gap-3">
                            <span>Candidate</span>
                            <span class="text-black dark:text-white">{{ data_get($sl1Network, 'summary.candidates', 0) }}</span>
                        </div>
                        <div class="flex justify-between gap-3">
                            <span>Rejected</span>
                            <span class="text-black dark:text-white">{{ data_get($sl1Network, 'summary.dry_run_rejected', 0) }}</span>
                        </div>
                    </div>
                </div>

                <div class="rounded border-2 border-black bg-neutral-50 dark:bg-neutral-900/50 p-4 shadow-[2px_2px_0px_#000000]">
                    <div class="flex items-center justify-between gap-3 mb-3">
                        <div>
                            <div class="text-[9px] font-black uppercase tracking-widest text-neutral-500">Peer Observation Layer</div>
                            <div class="text-sm font-black text-black dark:text-white">Admitted SL1 Peers</div>
                        </div>
                        <span class="text-[9px] font-black uppercase tracking-widest text-neutral-500">Host Authority Only</span>
                    </div>

                    <div class="grid gap-2">
                        @forelse (data_get($sl1Network, 'peers', []) as $peer)
                            <div class="rounded border border-black bg-white dark:bg-black/30 p-3">
                                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                                    <div class="min-w-0">
                                        <div class="font-black text-sm text-black dark:text-white truncate">{{ data_get($peer, 'name') }}</div>
                                        <div class="text-[10px] font-bold text-neutral-500 uppercase tracking-widest truncate">{{ data_get($peer, 'issuer') }}</div>
                                    </div>
                                    <div class="flex flex-wrap gap-1.5">
                                        <span @class([
                                            'px-2 py-0.5 text-[9px] font-black uppercase tracking-wider rounded border border-black',
                                            'text-[#22d3ee] bg-[#22d3ee]/10' => data_get($peer, 'status') === 'verified',
                                            'text-[#fbbf24] bg-[#f59e0b]/10' => data_get($peer, 'status') !== 'verified',
                                        ])>
                                            {{ strtoupper(data_get($peer, 'status', 'unknown')) }}
                                        </span>
                                        <span class="px-2 py-0.5 text-[9px] font-black uppercase tracking-wider rounded border border-black text-[#c084fc] bg-[#a855f7]/10">
                                            {{ strtoupper(data_get($peer, 'trust_state', 'unknown')) }}
                                        </span>
                                    </div>
                                </div>
                                <div class="mt-2 grid grid-cols-2 lg:grid-cols-4 gap-2 text-[10px] font-bold uppercase tracking-widest text-neutral-500 dark:text-neutral-400">
                                    <div>
                                        <div>Observed</div>
                                        <div class="text-black dark:text-white">{{ data_get($peer, 'observed_events', 0) }}</div>
                                    </div>
                                    <div>
                                        <div>Cursor</div>
                                        <div class="text-black dark:text-white">{{ data_get($peer, 'cursor', '0') }}</div>
                                    </div>
                                    <div>
                                        <div>Verified</div>
                                        <div class="text-black dark:text-white normal-case tracking-normal">{{ data_get($peer, 'last_verified_at') ?: 'never' }}</div>
                                    </div>
                                    <div>
                                        <div>Synced</div>
                                        <div class="text-black dark:text-white normal-case tracking-normal">{{ data_get($peer, 'last_synced_at') ?: 'never' }}</div>
                                    </div>
                                </div>
                                <div class="mt-2 text-[10px] font-bold text-neutral-500 truncate">
                                    node={{ data_get($peer, 'node_id') ?: 'unknown' }}
                                </div>
                            </div>
                        @empty
                            <div class="rounded border-2 border-dashed border-black bg-white dark:bg-black/20 p-4 text-xs font-bold text-neutral-500 dark:text-neutral-400">
                                No SL1 peers admitted yet. Admission is a local host authority decision; bridge discovery alone is not membership.
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>

            <div class="mt-4 rounded border-2 border-black bg-neutral-50 dark:bg-neutral-900/50 p-4 shadow-[2px_2px_0px_#000000]">
                <div class="flex items-center justify-between gap-3 mb-3">
                    <div>
                        <div class="text-[9px] font-black uppercase tracking-widest text-neutral-500">Discovery Layer</div>
                        <div class="text-sm font-black text-black dark:text-white">Bridge-Visible Candidates</div>
                    </div>
                    <span class="text-[9px] font-black uppercase tracking-widest text-neutral-500">Evidence Only</span>
                </div>

                <div class="grid gap-2">
                    @forelse (data_get($sl1Network, 'discovery_candidates', []) as $candidate)
                        <div class="rounded border border-dashed border-black bg-white dark:bg-black/30 p-3">
                            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                                <div class="min-w-0">
                                    <div class="font-black text-sm text-black dark:text-white truncate">{{ data_get($candidate, 'host_domain') ?: 'unknown host' }}</div>
                                    <div class="text-[10px] font-bold text-neutral-500 uppercase tracking-widest truncate">{{ data_get($candidate, 'issuer') ?: 'issuer pending' }}</div>
                                </div>
                                <div class="flex flex-wrap gap-1.5">
                                    <span class="px-2 py-0.5 text-[9px] font-black uppercase tracking-wider rounded border border-black text-[#fbbf24] bg-[#f59e0b]/10">
                                        {{ strtoupper(data_get($candidate, 'verification_state', 'join_request')) }}
                                    </span>
                                    <span class="px-2 py-0.5 text-[9px] font-black uppercase tracking-wider rounded border border-black text-neutral-500 bg-neutral-100 dark:bg-neutral-900">
                                        NOT ADMITTED
                                    </span>
                                </div>
                            </div>
                            <div class="mt-2 grid grid-cols-2 lg:grid-cols-4 gap-2 text-[10px] font-bold uppercase tracking-widest text-neutral-500 dark:text-neutral-400">
                                <div>
                                    <div>Request</div>
                                    <div class="text-black dark:text-white normal-case tracking-normal truncate">{{ data_get($candidate, 'request_id') }}</div>
                                </div>
                                <div>
                                    <div>Status</div>
                                    <div class="text-black dark:text-white">{{ data_get($candidate, 'status', 'observed') }}</div>
                                </div>
                                <div>
                                    <div>Artifacts</div>
                                    <div class="text-black dark:text-white">{{ data_get($candidate, 'artifact_count', 0) }}</div>
                                </div>
                                <div>
                                    <div>Authority</div>
                                    <div class="text-black dark:text-white">host-only</div>
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="rounded border-2 border-dashed border-black bg-white dark:bg-black/20 p-4 text-xs font-bold text-neutral-500 dark:text-neutral-400">
                            No bridge-visible discovery candidates. This does not affect already admitted peers.
                        </div>
                    @endforelse
                </div>
            </div>
        @endif
    </div>

    <!-- Nodes Grid -->
    <div class="grid gap-6 lg:grid-cols-2">
        @forelse ($servers as $server)
            <a href="{{ route('server.show', ['server_uuid' => data_get($server, 'uuid')]) }}" {{ wireNavigate() }}
                @class([
                    'card-neo flex flex-col bg-white dark:bg-[#090909] border-[3px] border-black shadow-[4px_4px_0px_#000000] rounded-lg overflow-hidden transition-transform hover:-translate-y-1',
                    '!border-[#f53003]' => !$server->settings->is_reachable || $server->settings->force_disabled,
                ])>
                
                <!-- Node Header -->
                <div class="p-4 border-b-2 border-black flex justify-between items-center bg-neutral-50 dark:bg-neutral-900/40">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full border-2 border-black flex items-center justify-center bg-black dark:bg-white text-white dark:text-black shadow-[1.5px_1.5px_0px_#000000]">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 12h14M12 5l7 7-7 7"></path></svg>
                        </div>
                        <div>
                            <div class="font-black text-base text-black dark:text-white tracking-tight" style="font-family: 'Space Grotesk', sans-serif;">
                                {{ $server->name }}
                            </div>
                            <div class="text-[10px] font-bold text-neutral-500 uppercase tracking-widest mt-0.5">
                                {{ $server->ip }}
                            </div>
                        </div>
                    </div>
                    <div>
                        @if ($server->settings->is_reachable && $server->settings->is_usable)
                            <div class="flex items-center gap-1.5 px-2 py-1 text-[10px] font-black uppercase tracking-wider rounded border border-black text-[#22d3ee] bg-[#22d3ee]/10 shadow-[1.5px_1.5px_0px_#000000]">
                                <span class="w-1.5 h-1.5 rounded-full bg-[#22d3ee] animate-pulse"></span>
                                ONLINE
                            </div>
                        @else
                            <div class="flex items-center gap-1.5 px-2 py-1 text-[10px] font-black uppercase tracking-wider rounded border border-black text-[#f53003] bg-[#f53003]/10 shadow-[1.5px_1.5px_0px_#000000]">
                                <span class="w-1.5 h-1.5 rounded-full bg-[#f53003]"></span>
                                OFFLINE
                            </div>
                        @endif
                    </div>
                </div>

                <!-- Node Body -->
                <div class="p-4">
                    <div class="text-xs font-semibold text-neutral-600 dark:text-neutral-400 line-clamp-2 min-h-[32px]">
                        {{ $server->description ?: 'No description provided.' }}
                    </div>
                    
                    <div class="mt-4 flex flex-wrap gap-2">
                        @if ($server->settings->is_swarm_manager)
                            <span class="px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wider rounded bg-[#a855f7]/10 text-[#c084fc] border border-[#a855f7]/30">
                                SWARM MANAGER
                            </span>
                        @endif
                        @if ($server->settings->is_swarm_worker)
                            <span class="px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wider rounded bg-[#22d3ee]/10 text-[#67e8f9] border border-[#22d3ee]/30">
                                SWARM WORKER
                            </span>
                        @endif
                        @if ($server->settings->is_build_server)
                            <span class="px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wider rounded bg-[#f59e0b]/10 text-[#fbbf24] border border-[#f59e0b]/30">
                                BUILD NODE
                            </span>
                        @endif
                        @if ($server->isLocalhost())
                            <span class="px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wider rounded bg-[#22d3ee]/10 text-[#67e8f9] border border-[#22d3ee]/30">
                                LOCALHOST
                            </span>
                        @endif
                    </div>

                    @if (!$server->settings->is_usable || $server->settings->force_disabled)
                        <div class="mt-4 flex flex-col gap-1 text-[10px] font-bold text-[#f53003] uppercase tracking-widest bg-[#f53003]/5 border border-[#f53003]/20 p-2 rounded">
                            @if (!$server->settings->is_usable)
                                <span>⚠ Not usable by orchestrator</span>
                            @endif
                            @if ($server->settings->force_disabled)
                                <span>⚠ Disabled by system</span>
                            @endif
                        </div>
                    @endif
                </div>
            </a>
        @empty
            <div class="col-span-full card-neo p-8 bg-neutral-100 dark:bg-neutral-900 border-[3px] border-black border-dashed text-center rounded-lg">
                <div class="text-neutral-500 dark:text-neutral-400 font-bold mb-4 tracking-widest uppercase">
                    NO NODES CONNECTED TO THE NETWORK.
                </div>
                <div class="text-xs font-semibold text-neutral-400">
                    Deploy a sovereign node to start participating in the infrastructure consensus.
                </div>
            </div>
        @endforelse
        @isset($error)
            <div class="col-span-full card-neo p-4 bg-red-50 dark:bg-red-900/20 border-[3px] border-[#f53003] text-center rounded-lg shadow-[4px_4px_0px_#000000]">
                <span class="text-sm font-bold text-[#f53003]">{{ $error }}</span>
            </div>
        @endisset
    </div>
</div>
