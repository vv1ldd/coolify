<div>
    <x-slot:title>Realm Operations | Sovereign</x-slot>

    @php
        $statusClass = function (?string $status): string {
            return match (strtoupper((string) $status)) {
                'OK', 'PASS', 'CONVERGED' => 'text-green-700 dark:text-green-400',
                'FAIL', 'FAILED', 'ERROR', 'DIVERGED' => 'text-red-700 dark:text-red-400',
                default => 'text-neutral-500',
            };
        };
    @endphp

    <div class="flex flex-col gap-6 pb-10">
        <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4 border-b-[3px] border-black pb-6">
            <div>
                <div class="text-[10px] font-black text-neutral-400 uppercase tracking-[0.3em] mb-1">SOVEREIGN RUNTIME</div>
                <h1 class="text-3xl font-black text-black leading-none uppercase">Realm Operations</h1>
                <p class="text-sm text-neutral-500 mt-2 font-mono">Observe -> Aggregate -> Display. Console shows evidence; Protocol defines meaning.</p>
            </div>
        </div>

        <div class="grid gap-3 md:grid-cols-4">
            @foreach (data_get($snapshot, 'sources', []) as $source)
                <div class="p-3 border-[2px] border-neutral-300 bg-neutral-50">
                    <div class="text-[10px] font-black uppercase tracking-widest text-neutral-500">{{ data_get($source, 'role') }}</div>
                    <div class="pt-1 text-xs font-mono text-neutral-700">{{ data_get($source, 'responsibility') }}</div>
                </div>
            @endforeach
        </div>

        <div class="flex items-center gap-3 px-4 py-3 border-[3px] border-neutral-400 bg-neutral-50">
            <div class="text-[10px] font-black uppercase tracking-widest text-neutral-600 font-mono">
                {{ data_get($snapshot, 'boundary.note') }}
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <div class="p-5 border-[3px] border-black">
                <div class="text-[10px] font-black uppercase tracking-widest text-neutral-500 mb-3">Deployment</div>
                <div class="space-y-2 font-mono text-sm">
                    <div><span class="text-neutral-500">Artifact image:</span> {{ data_get($snapshot, 'artifact.image_ref') }}</div>
                    <div><span class="text-neutral-500">Artifact digest:</span> {{ data_get($snapshot, 'artifact.image_digest') }}</div>
                    <div class="text-[10px] uppercase tracking-widest text-neutral-400">Source: {{ data_get($snapshot, 'artifact.source') }}</div>
                </div>
            </div>

            <div class="p-5 border-[3px] border-black">
                <div class="text-[10px] font-black uppercase tracking-widest text-neutral-500 mb-3">Protocol Identity</div>
                <div class="space-y-2 font-mono text-sm">
                    <div><span class="text-neutral-500">Package fingerprint:</span> {{ data_get($snapshot, 'protocol.package_fingerprint') }}</div>
                    <div><span class="text-neutral-500">Distribution digest:</span> {{ data_get($snapshot, 'protocol.distribution_digest') }}</div>
                    <div><span class="text-neutral-500">Protocol version:</span> {{ data_get($snapshot, 'protocol.protocol_version') }}</div>
                    <div class="text-[10px] uppercase tracking-widest text-neutral-400">Source: {{ data_get($snapshot, 'protocol.source') }}</div>
                </div>
            </div>

            <div class="p-5 border-[3px] border-black">
                <div class="text-[10px] font-black uppercase tracking-widest text-neutral-500 mb-3">Runtime Reality</div>
                <div class="space-y-2 font-mono text-sm">
                    <div><span class="text-neutral-500">History head:</span> {{ data_get($snapshot, 'runtime.history_head') }}</div>
                    <div><span class="text-neutral-500">State root:</span> {{ data_get($snapshot, 'runtime.state_root') }}</div>
                    <div><span class="text-neutral-500">Last transition:</span> {{ data_get($snapshot, 'runtime.last_transition') }}</div>
                    <div><span class="text-neutral-500">Runtime reachable:</span> {{ data_get($snapshot, 'runtime.reachable') ? 'true' : 'false' }}</div>
                    <div class="text-[10px] uppercase tracking-widest text-neutral-400">Source: {{ data_get($snapshot, 'runtime.source') }}</div>
                </div>
            </div>

            <div class="p-5 border-[3px] border-black">
                <div class="text-[10px] font-black uppercase tracking-widest text-neutral-500 mb-3">Verification Confidence</div>
                <div class="space-y-2 font-mono text-sm">
                    <div>
                        <span class="text-neutral-500">Semantic health:</span>
                        <span class="{{ $statusClass(data_get($snapshot, 'verification.semantic_health')) }}">
                            {{ data_get($snapshot, 'verification.semantic_health') }}
                        </span>
                    </div>
                    <div>
                        <span class="text-neutral-500">Shadow verifier:</span>
                        <span class="{{ $statusClass(data_get($snapshot, 'verification.shadow_verify')) }}">
                            {{ data_get($snapshot, 'verification.shadow_verify') }}
                        </span>
                    </div>
                    <div>
                        <span class="text-neutral-500">Verification result:</span>
                        <span class="{{ $statusClass(data_get($snapshot, 'verification.result')) }}">
                            {{ data_get($snapshot, 'verification.result') }}
                        </span>
                    </div>
                    <div><span class="text-neutral-500">Verification contract:</span> {{ data_get($snapshot, 'verification.result_contract_ref') }}</div>
                    @if (data_get($snapshot, 'verification.result_reason_code'))
                        <div><span class="text-neutral-500">Result reason code:</span> {{ data_get($snapshot, 'verification.result_reason_code') }}</div>
                    @endif
                    <div>
                        <span class="text-neutral-500">Certification:</span>
                        <span class="{{ $statusClass(data_get($snapshot, 'verification.conformance')) }}">
                            {{ data_get($snapshot, 'verification.conformance') }}
                        </span>
                    </div>
                    <div><span class="text-neutral-500">Verifier:</span> {{ data_get($snapshot, 'verification.verifier') }}</div>
                    <div><span class="text-neutral-500">Reason:</span> {{ data_get($snapshot, 'verification.reason') }}</div>
                    <div><span class="text-neutral-500">Checked at:</span> {{ data_get($snapshot, 'verification.checked_at') }}</div>
                    <div class="text-[10px] uppercase tracking-widest text-neutral-400">Source: {{ data_get($snapshot, 'verification.source') }}</div>
                </div>
            </div>
        </div>

        <div class="p-5 border-[3px] border-black">
            <div class="text-[10px] font-black uppercase tracking-widest text-neutral-500 mb-3">Mesh Convergence Evidence</div>
            <div class="space-y-2 font-mono text-sm">
                <div>
                    <span class="text-neutral-500">Result:</span>
                    <span class="{{ $statusClass(data_get($snapshot, 'mesh_convergence.result')) }}">
                        {{ data_get($snapshot, 'mesh_convergence.result') }}
                    </span>
                </div>
                <div><span class="text-neutral-500">Scope:</span> {{ data_get($snapshot, 'mesh_convergence.scope') }}</div>
                <div><span class="text-neutral-500">Contract:</span> {{ data_get($snapshot, 'mesh_convergence.comparison_contract_ref') }}</div>
                <div><span class="text-neutral-500">Reason:</span> {{ data_get($snapshot, 'mesh_convergence.reason') }}</div>
                <div><span class="text-neutral-500">Authority:</span> {{ data_get($snapshot, 'mesh_convergence.authority') }}</div>
                <div><span class="text-neutral-500">Source:</span> {{ data_get($snapshot, 'mesh_convergence.source') }}</div>
                <div class="text-[10px] uppercase tracking-widest text-neutral-400">Derived evidence artifact, not mesh health or consensus.</div>
            </div>

            @if (count(data_get($snapshot, 'runtime_observations', [])) > 0)
                <div class="mt-4 space-y-3">
                    @foreach (data_get($snapshot, 'runtime_observations', []) as $observation)
                        <div class="p-3 border-[2px] border-neutral-300 bg-neutral-50">
                            <div class="text-[10px] font-black uppercase tracking-widest text-neutral-500 mb-2">
                                Runtime Observation: {{ data_get($observation, 'node_id') }}
                            </div>
                            <div class="space-y-1 font-mono text-xs">
                                <div><span class="text-neutral-500">History head kind:</span> {{ data_get($observation, 'history_head_kind') }}</div>
                                <div><span class="text-neutral-500">History head:</span> {{ data_get($observation, 'history_head') }}</div>
                                <div><span class="text-neutral-500">State root:</span> {{ data_get($observation, 'state_root') }}</div>
                                <div><span class="text-neutral-500">Event count:</span> {{ data_get($observation, 'event_count', 'UNKNOWN') }}</div>
                                <div><span class="text-neutral-500">Reachable:</span> {{ data_get($observation, 'reachable') ? 'true' : 'false' }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="p-5 border-[3px] border-neutral-400 bg-neutral-50">
            <div class="text-[10px] font-black uppercase tracking-widest text-neutral-500 mb-3">Process Health (Not Semantic Health)</div>
            <div class="space-y-2 font-mono text-sm">
                <div>
                    <span class="text-neutral-500">Status:</span>
                    <span class="{{ $statusClass(data_get($snapshot, 'process_health.status')) }}">
                        {{ data_get($snapshot, 'process_health.status') }}
                    </span>
                </div>
                <div><span class="text-neutral-500">Note:</span> {{ data_get($snapshot, 'process_health.note') }}</div>
                @if (data_get($snapshot, 'process_health.target_node'))
                    <div><span class="text-neutral-500">Target node:</span> {{ data_get($snapshot, 'process_health.target_node') }}</div>
                @endif
            </div>
        </div>

        <div class="p-5 border-[3px] border-neutral-300">
            <div class="text-[10px] font-black uppercase tracking-widest text-neutral-500 mb-3">Evidence Graph Navigation</div>
            <div class="text-[10px] uppercase tracking-widest text-neutral-400 mb-4">Blade traverses. Blade does not infer.</div>

            @if (count(data_get($snapshot, 'evidence_graph.nodes', [])) > 0)
                <div class="space-y-3">
                    @foreach (data_get($snapshot, 'evidence_graph.nodes', []) as $node)
                        <div class="p-3 border-[2px] border-neutral-300 bg-neutral-50">
                            <div class="text-[10px] font-black uppercase tracking-widest text-neutral-500 mb-2">
                                {{ data_get($node, 'kind') }}: {{ data_get($node, 'id') }}
                            </div>
                            <div class="space-y-1 font-mono text-xs">
                                <div><span class="text-neutral-500">Value:</span> {{ data_get($node, 'value') }}</div>
                                <div><span class="text-neutral-500">Trust:</span> {{ data_get($node, 'trust_state') }}</div>
                                <div><span class="text-neutral-500">Authority:</span> {{ data_get($node, 'authority') }}</div>
                                <div><span class="text-neutral-500">Source:</span> {{ data_get($node, 'source') }}</div>
                                @if (count(data_get($node, 'derived_from', [])) > 0)
                                    <div><span class="text-neutral-500">Derived from:</span> {{ implode(', ', data_get($node, 'derived_from', [])) }}</div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="mt-4 space-y-2">
                    <div class="text-[10px] font-black uppercase tracking-widest text-neutral-500">Evidence Edges</div>
                    @foreach (data_get($snapshot, 'evidence_graph.edges', []) as $edge)
                        <div class="p-2 border border-neutral-200 font-mono text-xs">
                            <span class="text-neutral-500">{{ data_get($edge, 'from') }}</span>
                            <span class="text-neutral-400"> --{{ data_get($edge, 'relation') }} / {{ data_get($edge, 'edge_kind') }}--> </span>
                            <span class="text-neutral-500">{{ data_get($edge, 'to') }}</span>
                            <div class="pt-1 text-neutral-400">{{ data_get($edge, 'reason') }}</div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="p-5 border-[3px] border-neutral-300">
            <div class="text-[10px] font-black uppercase tracking-widest text-neutral-500 mb-3">Evidence Graph Validation</div>
            <div class="space-y-2 font-mono text-sm">
                <div>
                    <span class="text-neutral-500">Valid:</span>
                    <span class="{{ data_get($snapshot, 'graph_validation.valid') ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400' }}">
                        {{ data_get($snapshot, 'graph_validation.valid') ? 'true' : 'false' }}
                    </span>
                </div>
                <div><span class="text-neutral-500">Schema:</span> {{ data_get($snapshot, 'graph_validation.schema_ref') }}</div>
                <div><span class="text-neutral-500">Validator:</span> {{ data_get($snapshot, 'graph_validation.validator_version') }}</div>
                <div><span class="text-neutral-500">Checked nodes:</span> {{ data_get($snapshot, 'graph_validation.checked_nodes') }}</div>
                <div><span class="text-neutral-500">Checked edges:</span> {{ data_get($snapshot, 'graph_validation.checked_edges') }}</div>
                <div class="text-[10px] uppercase tracking-widest text-neutral-400">Valid graph means honest explanation structure, not valid Realm.</div>
            </div>
        </div>

        <div class="p-5 border-[3px] border-neutral-300">
            <div class="text-[10px] font-black uppercase tracking-widest text-neutral-500 mb-3">Evidence References</div>
            <pre class="p-3 overflow-auto text-xs font-mono bg-neutral-100 dark:bg-neutral-900">{{ json_encode(data_get($snapshot, 'evidence_refs', []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            <div class="pt-2 text-[10px] font-mono uppercase tracking-widest text-neutral-400">
                Generated at {{ data_get($snapshot, 'generated_at') }}
            </div>
        </div>
    </div>
</div>
