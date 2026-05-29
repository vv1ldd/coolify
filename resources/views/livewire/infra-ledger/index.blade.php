<div>
    <x-slot:title>Audit Ledger | Sovereign</x-slot>

    <div class="flex flex-col gap-6 pb-10">

        {{-- ═══ PAGE HEADER ═══ --}}
        <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4 border-b-[3px] border-black pb-6">
            <div>
                <div class="text-[10px] font-black text-neutral-400 uppercase tracking-[0.3em] mb-1">SOVEREIGN RUNTIME</div>
                <h1 class="text-3xl font-black text-black leading-none uppercase">Audit & Governance</h1>
                <p class="text-sm text-neutral-500 mt-2 font-mono">Consortium Operational Constitution — Verifiable State Transitions</p>
            </div>
            <div class="flex flex-col sm:flex-row gap-2">
                {{-- Chain Integrity Verifier --}}
                <button wire:click="verifyChain"
                    class="group flex items-center gap-2 px-4 py-2 font-black text-xs uppercase tracking-widest
                           border-[3px] border-black bg-black text-white
                           shadow-[3px_3px_0_#6b7280] hover:shadow-[1px_1px_0_#6b7280]
                           hover:translate-x-[2px] hover:translate-y-[2px]
                           transition-all duration-100 whitespace-nowrap">
                    <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.955 11.955 0 003 12c0 3.31 1.348 6.31 3.526 8.486M20.402 6A11.956 11.956 0 0121 12c0 3.31-1.348 6.31-3.526 8.486" />
                    </svg>
                    Verify Chain
                </button>
            </div>
        </div>

        {{-- ═══ INTEGRITY STATUS BANNER ═══ --}}
        @if(session('success'))
            <div class="flex items-center gap-3 px-4 py-3 border-[3px] border-green-500 bg-green-500/5">
                <div class="w-2 h-2 rounded-full bg-green-500"></div>
                <span class="font-black text-xs uppercase tracking-widest text-green-700 font-mono">{{ session('success') }}</span>
            </div>
        @endif
        @if($errors->has('sl1'))
            <div class="flex items-center gap-3 px-4 py-3 border-[3px] border-red-600 bg-red-600/5">
                <div class="w-2 h-2 rounded-full bg-red-600 animate-pulse"></div>
                <span class="font-black text-xs uppercase tracking-widest text-red-700 font-mono">{{ $errors->first('sl1') }}</span>
            </div>
        @endif

        @if($integrityChecked)
            @if($integrityStatus === 'valid')
                <div class="flex items-center gap-3 px-4 py-3 border-[3px] border-green-500 bg-green-500/5">
                    <div class="w-2 h-2 rounded-full bg-green-500"></div>
                    <span class="font-black text-xs uppercase tracking-widest text-green-700 font-mono">✔ CONSENSUS REACHED — Chain integrity verified. All fingerprints match.</span>
                </div>
            @else
                <div class="flex items-center gap-3 px-4 py-3 border-[3px] border-red-600 bg-red-600/5">
                    <div class="w-2 h-2 rounded-full bg-red-600 animate-pulse"></div>
                    <span class="font-black text-xs uppercase tracking-widest text-red-700 font-mono">✘ INTEGRITY VIOLATION — Chain hash mismatch detected. Run: php artisan sovereign:verify-infra-ledger</span>
                </div>
            @endif
        @endif

        {{-- ==========================================================================
           🏛️ PENDING INTENT MEMPOOL (DATACENTER/MILITARY HIGH-CONTRAST CONSOLE)
           ========================================================================== --}}
        @if(isset($pendingIntents) && $pendingIntents->isNotEmpty())
            <div class="border-[3px] border-black bg-neutral-950 p-6 shadow-[5px_5px_0_#000000] rounded-sm">
                <div class="flex items-center justify-between border-b-[2px] border-neutral-800 pb-3 mb-6">
                    <div class="flex items-center gap-2">
                        <div class="w-2.5 h-2.5 rounded-full bg-amber-500 animate-pulse"></div>
                        <h2 class="text-xs font-black uppercase tracking-[0.25em] text-amber-500 font-mono">PENDING INTENTS MEMPOOL (CONSTITUTIONAL CLEARANCE REQUIRED)</h2>
                    </div>
                    <span class="text-[9px] font-mono text-neutral-400 uppercase tracking-widest">Awaiting Quorum Approvals</span>
                </div>

                <div class="flex flex-col gap-6">
                    @foreach($pendingIntents as $intent)
                        @php
                            $policyService = app(\App\Services\PolicyEngine::class);
                            $rule = $policyService->getRule($intent->event_type);
                            $reqSigs = $rule ? $rule['signatures_required'] : 1;
                            $curSigs = count($intent->signatures);
                            $isSuccess = $curSigs >= $reqSigs;

                            // Live simulation and risk computations
                            $risk = $policyService->getOperationalRisk($intent->event_type, $intent->payload);
                            $sim = $policyService->simulateTransition($intent->event_type, $intent->payload);
                        @endphp
                        <div class="flex flex-col bg-neutral-900 border-[2px] border-neutral-800 rounded-sm">
                            
                            {{-- Row 1: Core Action Review, Diff, Timeline --}}
                            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 p-5">
                                
                                {{-- 1. Intent Review Panel (Col 1-4) --}}
                                <div class="lg:col-span-4 flex flex-col gap-4">
                                    <div class="text-[9px] font-black uppercase tracking-widest text-neutral-400 font-mono border-b border-neutral-800 pb-1.5">
                                        INTENT REVIEW PANEL
                                    </div>
                                    <div class="flex flex-col gap-2 font-mono">
                                        <div>
                                            <span class="text-[9px] text-neutral-500 block uppercase">ACTION:</span>
                                            <span class="text-xs text-amber-500 font-bold uppercase tracking-wider">{{ str_replace('.', ' › ', $intent->event_type) }}</span>
                                        </div>
                                        <div>
                                            <span class="text-[9px] text-neutral-500 block uppercase">TARGET ENTITY:</span>
                                            <span class="text-xs text-white font-bold">{{ data_get($intent->payload, 'server_name') ?? data_get($intent->payload, 'application_name') ?? 'System Substrate' }}</span>
                                        </div>
                                        <div>
                                            <span class="text-[9px] text-neutral-500 block uppercase">CONSTITUTIONAL RULE:</span>
                                            <span class="text-xs text-neutral-300">{{ $rule ? $rule['title'] : 'Strict clearance required' }}</span>
                                        </div>
                                        <div class="mt-2">
                                            <span class="text-[9px] text-neutral-500 block uppercase">SIGNATURES STATUS:</span>
                                            <div class="flex items-center gap-2 mt-1">
                                                <span class="text-xs font-bold text-amber-500">
                                                    {{ $curSigs }} / {{ $reqSigs }} APPROVALS
                                                </span>
                                                <span class="text-neutral-500 text-xs">
                                                    [@for($i=1; $i<=$reqSigs; $i++)
                                                        @if($i <= $curSigs)<span class="text-amber-500">■</span>@else<span class="text-neutral-700">□</span>@endif
                                                    @endfor]
                                                </span>
                                            </div>
                                        </div>
                                    </div>

                                    {{-- Action SL1 signing button --}}
                                    <a href="{{ route('auth.sl1.intent.redirect', ['intent' => $intent->id]) }}"
                                        class="w-full mt-2 py-2.5 px-4 text-[10px] font-black uppercase tracking-widest font-mono text-center
                                               block
                                               border-[2px] border-amber-500 bg-amber-500/10 text-amber-500 hover:bg-amber-500 hover:text-black
                                               transition-all duration-100 cursor-pointer">
                                        [ SIGN WITH SL1 IDENTITY ]
                                    </a>
                                </div>

                                {{-- 2. Intent Diff View (Col 5-8) --}}
                                <div class="lg:col-span-4 flex flex-col gap-4">
                                    <div class="text-[9px] font-black uppercase tracking-widest text-neutral-400 font-mono border-b border-neutral-800 pb-1.5">
                                        INTENT DIFF VIEW
                                    </div>
                                    <div class="bg-black/40 border border-neutral-800 rounded-sm p-4 h-full font-mono text-[10px] text-neutral-400 leading-relaxed overflow-x-auto min-h-[120px]">
                                        @if($intent->event_type === 'server.removed')
                                            <div class="text-red-500">- node: {{ data_get($intent->payload, 'server_name') }}</div>
                                            <div class="text-red-500">- status: active</div>
                                            <div class="text-red-500">- consensus: trusted</div>
                                            <div class="text-green-500">+ status: DECOMMISSIONED</div>
                                            <div class="text-green-500">+ consensus: EXITED</div>
                                        @elseif($intent->event_type === 'application.deploy')
                                            <div class="text-red-500">- status: idle</div>
                                            <div class="text-green-500">+ deployment_intent: launched</div>
                                            <div class="text-green-500">+ force_rebuild: {{ data_get($intent->payload, 'force_rebuild') ? 'true' : 'false' }}</div>
                                            <div class="text-green-500">+ target_host: {{ data_get($intent->payload, 'application_name') }}</div>
                                        @elseif($intent->event_type === 'application.stop')
                                            <div class="text-red-500">- active_replicas: 1</div>
                                            <div class="text-red-500">- status: running</div>
                                            <div class="text-green-500">+ status: TERMINATED</div>
                                            <div class="text-green-500">+ active_replicas: 0</div>
                                        @else
                                            @foreach($intent->payload as $k => $v)
                                                <div><span class="text-neutral-500">{{ $k }}</span>: {{ is_array($v) ? json_encode($v) : $v }}</div>
                                            @endforeach
                                        @endif
                                    </div>
                                </div>

                                {{-- 3. Consensus Timeline (Col 9-12) --}}
                                <div class="lg:col-span-4 flex flex-col gap-4">
                                    <div class="text-[9px] font-black uppercase tracking-widest text-neutral-400 font-mono border-b border-neutral-800 pb-1.5">
                                        CONSENSUS TIMELINE
                                    </div>
                                    <div class="flex flex-col gap-3 font-mono text-[9px] text-neutral-400">
                                        @foreach($intent->timeline as $step)
                                            <div class="flex items-start gap-2.5">
                                                <span class="text-neutral-500 whitespace-nowrap">{{ \Carbon\Carbon::parse($step['timestamp'])->format('H:i:s') }}</span>
                                                <div class="flex flex-col">
                                                    <span class="text-white font-bold">{{ $step['action'] }}</span>
                                                    <span class="text-neutral-500 break-all">{{ $step['actor'] }} — {{ $step['detail'] }}</span>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>

                            {{-- Row 2: Strict Military Risk Assessment & Cluster State Simulation --}}
                            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 p-5 border-t border-neutral-800/80 bg-neutral-950/40">
                                
                                {{-- 4. Operational Risk Classification (Col 1-6) --}}
                                <div class="lg:col-span-6 flex flex-col gap-2.5">
                                    <div class="text-[9px] font-black uppercase tracking-widest text-neutral-500 font-mono flex items-center gap-1.5">
                                        <span>🛡️ OPERATIONAL RISK CLASSIFICATION</span>
                                        <span class="text-neutral-700">|</span>
                                        <span class="text-[9px] font-bold @if($risk['risk_level'] === 'CRITICAL' || $risk['risk_level'] === 'HIGH') text-red-500 @else text-amber-500 @endif animate-pulse">
                                            RISK: {{ $risk['risk_level'] }}
                                        </span>
                                    </div>
                                    
                                    <div class="grid grid-cols-2 gap-4 font-mono text-[10px] text-neutral-400 leading-relaxed bg-black/20 p-3 border border-neutral-800/60 rounded-sm">
                                        <div>
                                            <span class="text-neutral-500 block text-[9px] uppercase">OPERATIONAL IMPACT:</span>
                                            <span class="text-white font-bold">{{ $risk['impact'] }}</span>
                                        </div>
                                        <div>
                                            <span class="text-neutral-500 block text-[9px] uppercase">AFFECTED INSTANCES:</span>
                                            <span class="text-neutral-200 font-bold">{{ $risk['nodes'] }}</span>
                                        </div>
                                        <div class="col-span-2 border-t border-neutral-900 pt-2 mt-1">
                                            <span class="text-neutral-500 text-[9px] uppercase">PROJECTED RECOVERY DURATION:</span>
                                            <span class="text-amber-500 font-bold ml-1">{{ $risk['recovery'] }}</span>
                                        </div>
                                    </div>
                                </div>

                                {{-- 5. State Transition Simulator (Col 7-12) --}}
                                <div class="lg:col-span-6 flex flex-col gap-2.5">
                                    <div class="text-[9px] font-black uppercase tracking-widest text-neutral-500 font-mono">
                                        🔬 PRE-RELEASE STATE TRANSITION SIMULATOR
                                    </div>
                                    
                                    <div class="grid grid-cols-2 gap-4 font-mono text-[10px] text-neutral-400 leading-relaxed bg-black/20 p-3 border border-neutral-800/60 rounded-sm">
                                        <div>
                                            <span class="text-neutral-500 block text-[9px] uppercase">PROJECTED HEALTH AFTER:</span>
                                            <span class="text-white font-bold">{{ $sim['health'] }}</span>
                                        </div>
                                        <div>
                                            <span class="text-neutral-500 block text-[9px] uppercase">CONSENSUS QUORUM:</span>
                                            <span class="font-bold @if(str_contains($sim['quorum'], 'WARNING')) text-red-500 @else text-green-500 @endif">{{ $sim['quorum'] }}</span>
                                        </div>
                                        <div>
                                            <span class="text-neutral-500 block text-[9px] uppercase">AFFECTED WORKLOADS:</span>
                                            <span class="text-neutral-200 font-bold">{{ $sim['affected'] }} live containers</span>
                                        </div>
                                        <div>
                                            <span class="text-neutral-500 block text-[9px] uppercase">PROJECTED FAILOVER:</span>
                                            <span class="font-bold @if(str_contains($sim['failover'], 'WARNING')) text-red-500 @else text-green-500 @endif">{{ $sim['failover'] }}</span>
                                        </div>
                                    </div>
                                </div>

                            </div>
                            
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- ═══ FILTERS ═══ --}}
        <div class="flex flex-wrap items-center gap-3">
            <span class="text-[10px] font-black uppercase tracking-widest text-neutral-400">Filter by Intent:</span>
            <button wire:click="$set('filterType', null)"
                @class([
                    'px-3 py-1 text-xs font-black uppercase tracking-widest border-[2px] transition-all',
                    'border-black bg-black text-white' => !$filterType,
                    'border-black bg-white text-black hover:bg-black hover:text-white' => $filterType,
                ])>
                ALL
            </button>
            @foreach($eventTypes as $type)
                <button wire:click="$set('filterType', '{{ $type }}')"
                    @class([
                        'px-3 py-1 text-xs font-black uppercase tracking-widest border-[2px] transition-all',
                        'border-black bg-black text-white' => $filterType === $type,
                        'border-black bg-white text-black hover:bg-black hover:text-white' => $filterType !== $type,
                    ])>
                    {{ str_replace('.', ' › ', $type) }}
                </button>
            @endforeach
        </div>

        {{-- ═══ LEDGER ENTRIES ═══ --}}
        @if($entries->isEmpty())
            <div class="flex flex-col items-center justify-center py-20 border-[3px] border-dashed border-black/20 gap-4">
                <div class="text-4xl font-black text-black/10">∅</div>
                <div class="text-sm font-black uppercase tracking-widest text-neutral-400">No Execution Intents Recorded Yet</div>
                <div class="text-xs text-neutral-400 font-mono">Deploy or restart an application to begin the audit chain.</div>
            </div>
        @else
            <div class="flex flex-col gap-3">
                @foreach($entries as $entry)
                    @php
                        $isSuccess = data_get($entry->output_state, 'result') !== 'failed';
                        $shortFp   = substr($entry->fingerprint, 0, 16);
                        $shortPrev = $entry->previous_fingerprint ? substr($entry->previous_fingerprint, 0, 8) : '0000000000000000';
                    @endphp
                    <div class="border-[3px] border-black bg-white shadow-[3px_3px_0_#000000] hover:shadow-[1px_1px_0_#000000] hover:translate-x-[2px] hover:translate-y-[2px] transition-all duration-100">

                        {{-- Intent Header --}}
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 px-4 py-3 border-b-[2px] border-black bg-black">
                            <div class="flex items-center gap-3">
                                {{-- Event Type Badge --}}
                                <span class="px-2 py-0.5 font-black text-[10px] uppercase tracking-widest text-black bg-white border-[2px] border-white">
                                    {{ str_replace('.', ' › ', $entry->event_type) }}
                                </span>
                                {{-- Entity --}}
                                <span class="font-mono text-xs text-neutral-300">
                                    {{ $entry->entity_name }}
                                </span>
                            </div>
                            {{-- Timestamp --}}
                            <span class="font-mono text-[10px] text-neutral-400 whitespace-nowrap">
                                {{ $entry->created_at->format('Y-m-d H:i:s') }} UTC
                            </span>
                        </div>

                        {{-- Intent Body --}}
                        <div class="grid grid-cols-1 sm:grid-cols-3 divide-y sm:divide-y-0 sm:divide-x divide-black/10 px-4 py-3 gap-y-3 sm:gap-y-0">

                            {{-- TxID / Fingerprint --}}
                            <div class="flex flex-col gap-1 sm:pr-4">
                                <div class="text-[9px] font-black uppercase tracking-widest text-neutral-400">TxID (SHA-256)</div>
                                <div class="font-mono text-xs text-black font-bold" title="{{ $entry->fingerprint }}">
                                    {{ $shortFp }}...
                                </div>
                                <div class="text-[9px] font-mono text-neutral-400" title="{{ $entry->previous_fingerprint }}">
                                    ← prev: {{ $shortPrev }}...
                                </div>
                            </div>

                            {{-- Actor Identity --}}
                            <div class="flex flex-col gap-1 sm:px-4">
                                <div class="text-[9px] font-black uppercase tracking-widest text-neutral-400">Actor (DID:SYS)</div>
                                <div class="font-mono text-xs text-black break-all">
                                    {{ $entry->trigger_source ?? 'DID:SYS|SERVICE:#system' }}
                                </div>
                            </div>

                            {{-- Payload Preview --}}
                            <div class="flex flex-col gap-1 sm:pl-4">
                                <div class="text-[9px] font-black uppercase tracking-widest text-neutral-400">Intent Payload</div>
                                <div class="font-mono text-[10px] text-neutral-600 leading-relaxed">
                                    @foreach(array_slice($entry->payload ?? [], 0, 3) as $k => $v)
                                        <span class="text-black font-bold">{{ $k }}</span>: {{ is_bool($v) ? ($v ? 'true' : 'false') : Str::limit((string)$v, 24) }}<br>
                                    @endforeach
                                </div>
                            </div>
                        </div>

                        {{-- MDK Kernel Proof footer --}}
                        <div class="flex items-center gap-2 px-4 py-1.5 bg-black/5 border-t border-black/10">
                            <span class="text-[9px] font-black uppercase tracking-widest text-neutral-400">
                                KERNEL: {{ data_get($entry->meta, 'constitution', '—') }}
                            </span>
                            <span class="text-neutral-300">·</span>
                            <span class="text-[9px] font-mono text-neutral-400">
                                {{ data_get($entry->meta, 'determinism', '—') }}
                            </span>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Pagination --}}
            <div class="mt-4 font-mono text-xs">
                {{ $entries->links() }}
            </div>
        @endif
    </div>
</div>
