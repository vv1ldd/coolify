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
