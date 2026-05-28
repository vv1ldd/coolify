<div>
    <x-slot:title>
        Dashboard | Coolify
    </x-slot>
    @if (session('error'))
        <span x-data x-init="$wire.emit('error', '{{ session('error') }}')" />
    @endif
    <h1>Dashboard</h1>
    <div class="subtitle mb-6">Your self-hosted infrastructure.</div>

    <div class="card-neo mb-6 p-6 relative overflow-hidden flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 bg-gradient-to-br dark:from-[rgba(168,85,247,0.12)] dark:via-[#0d0718] dark:to-[rgba(34,211,238,0.06)] bg-[#0d0718] border-[3px] border-black shadow-[4px_4px_0px_#000000] rounded-lg">
        <div>
            <div class="flex gap-2 mb-3">
                <span class="px-2 py-0.5 text-[9px] font-black uppercase tracking-wider rounded border border-black text-[#c084fc] bg-[#a855f7]/10" 
                      style="box-shadow: 1.5px 1.5px 0px #000000;">
                    SOVEREIGN SYSTEMS
                </span>
                <span class="px-2 py-0.5 text-[9px] font-black uppercase tracking-wider rounded border border-black text-[#22d3ee] bg-[#22d3ee]/10"
                      style="box-shadow: 1.5px 1.5px 0px #000000;">
                    INFRASTRUCTURE SHIELDED
                </span>
            </div>
            <h2 class="text-2xl font-black text-black dark:text-white m-0 tracking-tight" style="font-family: 'Space Grotesk', sans-serif;">
                SOVEREIGN PORTAL
            </h2>
            <p class="text-neutral-600 dark:text-neutral-400 mt-1.5 text-xs font-semibold max-w-2xl leading-relaxed">
                Добро пожаловать в Sovereign Infrastructure Portal — единую консоль управления автоматическими деплоями, серверами и изолированными крипто-нодами вашей приватной сети.
            </p>
        </div>
        <!-- Right side quick balance/mandate indicator if active -->
        <div class="flex flex-col gap-1.5">
            <span class="text-[10px] font-bold text-neutral-400 uppercase tracking-widest">Network status</span>
            <div class="flex items-center gap-1.5 bg-[#22d3ee]/15 text-[#67e8f9] border-2 border-black px-2 py-1 rounded font-bold uppercase tracking-widest text-[9px]" style="box-shadow: 2px 2px 0px #000000; width: fit-content;">
                <div class="w-1.5 h-1.5 rounded-full bg-[#22d3ee] animate-pulse border border-black"></div>
                <span>CLEARING: ACTIVE</span>
            </div>
        </div>
    </div>

    <!-- 📊 Sovereign Quick Infrastructure Metrics Grid -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div class="card-neo p-4 flex flex-col justify-between bg-white dark:bg-[#090909] border-[3px] border-black shadow-[4px_4px_0px_#000000] rounded-lg min-h-[90px]">
            <div>
                <div class="text-[10px] font-bold text-neutral-600 dark:text-neutral-400 uppercase tracking-widest">Active Projects</div>
                <div class="text-xl font-black text-black dark:text-white mt-1" style="font-family: 'Space Grotesk', sans-serif;">
                    {{ $projects->count() }} <span class="text-neutral-500 text-xs font-semibold">environments</span>
                </div>
            </div>
        </div>
        <div class="card-neo p-4 flex flex-col justify-between bg-white dark:bg-[#090909] border-[3px] border-black shadow-[4px_4px_0px_#000000] rounded-lg min-h-[90px]">
            <div>
                <div class="text-[10px] font-bold text-neutral-600 dark:text-neutral-400 uppercase tracking-widest">Validated Servers</div>
                <div class="text-xl font-black text-[#c084fc] mt-1" style="font-family: 'Space Grotesk', sans-serif;">
                    {{ $servers->count() }} <span class="text-neutral-500 text-xs font-semibold">nodes online</span>
                </div>
            </div>
        </div>
        <div class="card-neo p-4 flex flex-col justify-between bg-white dark:bg-[#090909] border-[3px] border-black shadow-[4px_4px_0px_#000000] rounded-lg min-h-[90px]">
            <div>
                <div class="text-[10px] font-bold text-neutral-600 dark:text-neutral-400 uppercase tracking-widest">Crypto Identity</div>
                <div class="text-xl font-black text-[#22d3ee] mt-1" style="font-family: 'Space Grotesk', sans-serif;">
                    Touch ID / Passkey <span class="text-neutral-500 text-xs font-semibold">secured</span>
                </div>
            </div>
        </div>
    </div>

    <!-- 🏛️ Sovereign Audit Ledger (Крипто-История) Widget -->
    @if ($successMessage)
        <div class="card-neo mb-6 p-4 flex justify-between items-center bg-[#22d3ee]/10 text-[#67e8f9] border-2 border-[#22d3ee] rounded" 
             style="box-shadow: 4px 4px 0px #22d3ee; border: 3px solid #22d3ee;">
            <div class="flex items-center gap-2">
                <svg class="w-5 h-5 text-[#67e8f9] animate-bounce" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                </svg>
                <span class="text-xs font-black uppercase tracking-wider">{{ $successMessage }}</span>
            </div>
            <button wire:click="dismissMessage" class="text-xs font-black uppercase bg-transparent text-[#67e8f9] hover:text-white border border-[#22d3ee] px-2 py-0.5 rounded cursor-pointer transition">
                Dismiss
            </button>
        </div>
    @endif

    <div class="card-neo mb-8 p-6 bg-white dark:bg-[#090909] border-[3px] border-black shadow-[4px_4px_0px_#000000] rounded-lg">
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6 pb-4 border-b-2 border-neutral-200 dark:border-neutral-800">
            <div>
                <div class="flex items-center gap-2">
                    <h3 class="text-lg font-black text-black dark:text-white m-0 tracking-tight" style="font-family: 'Space Grotesk', sans-serif;">
                        SOVEREIGN AUDIT LEDGER
                    </h3>
                    <span class="px-2 py-0.5 text-[8px] font-black uppercase tracking-wider rounded border border-black text-[#22d3ee] bg-[#22d3ee]/10" 
                          style="box-shadow: 1px 1px 0px #000000;">
                        Consortium L1 Verified
                    </span>
                </div>
                <p class="text-neutral-600 dark:text-neutral-400 mt-1 text-[11px] font-semibold leading-relaxed">
                    Deploys, consensus shifts, and server hardening actions recorded onto an immutable, verifiable chain.
                </p>
            </div>
            <button wire:click="auditState" wire:loading.attr="disabled"
                    class="bg-[#a855f7] text-white font-black uppercase tracking-widest text-[10px] px-4 py-2 border-2 border-black rounded transition hover:-translate-y-0.5"
                    style="box-shadow: 3px 3px 0px #000000; font-family: 'Space Grotesk', sans-serif;">
                <span wire:loading.remove>Audit State Checkpoint</span>
                <span wire:loading>Sealing Consensus...</span>
            </button>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="border-b-2 border-black bg-neutral-100 dark:bg-neutral-900/60">
                        <th class="p-3 text-[10px] font-black uppercase tracking-widest text-neutral-600 dark:text-neutral-400">Timestamp</th>
                        <th class="p-3 text-[10px] font-black uppercase tracking-widest text-neutral-600 dark:text-neutral-400">Event Type</th>
                        <th class="p-3 text-[10px] font-black uppercase tracking-widest text-neutral-600 dark:text-neutral-400">Payload Details</th>
                        <th class="p-3 text-[10px] font-black uppercase tracking-widest text-neutral-600 dark:text-neutral-400">Cryptographic Hash (SHA-256)</th>
                        <th class="p-3 text-[10px] font-black uppercase tracking-widest text-neutral-600 dark:text-neutral-400">L1 Signature</th>
                        <th class="p-3 text-[10px] font-black uppercase tracking-widest text-neutral-600 dark:text-neutral-400">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-900">
                    @foreach (array_slice($auditEvents, 0, 5) as $event)
                        <tr class="hover:bg-neutral-100 dark:hover:bg-neutral-900/30 transition">
                            <td class="p-3 text-[11px] font-bold text-neutral-600 dark:text-neutral-400 whitespace-nowrap">
                                {{ $event['timestamp'] }}
                            </td>
                            <td class="p-3">
                                <span class="px-2 py-0.5 text-[9px] font-black uppercase tracking-wider rounded border border-black text-amber-500 bg-amber-500/10" 
                                      style="box-shadow: 1px 1px 0px #000000;">
                                    {{ $event['event_type'] }}
                                </span>
                            </td>
                            <td class="p-3 text-xs font-semibold text-neutral-800 dark:text-neutral-200">
                                {{ $event['details'] }}
                            </td>
                            <td class="p-3">
                                <code class="px-1.5 py-0.5 rounded bg-neutral-100 dark:bg-black text-[#c084fc] border border-neutral-300 dark:border-neutral-800 text-[10px] font-bold" title="{{ $event['hash'] }}">
                                    {{ substr($event['hash'], 0, 10) }}...{{ substr($event['hash'], -8) }}
                                </code>
                            </td>
                            <td class="p-3">
                                <code class="px-1.5 py-0.5 rounded bg-neutral-100 dark:bg-black text-[#67e8f9] border border-neutral-300 dark:border-neutral-800 text-[10px] font-bold">
                                    {{ $event['signature'] }}
                                </code>
                            </td>
                            <td class="p-3">
                                <div class="flex items-center gap-1.5 text-[#22d3ee] font-bold uppercase tracking-widest text-[9px]">
                                    <span class="w-1.5 h-1.5 rounded-full bg-[#22d3ee] animate-pulse border border-black"></span>
                                    <span>{{ $event['status'] }}</span>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <section class="-mt-2">
        <div class="flex items-center gap-2 pb-2">
            <h3>Projects</h3>
            @if ($projects->count() > 0)
                <x-modal-input buttonTitle="Add" title="New Project">
                    <x-slot:content>
                        <button
                            class="flex items-center justify-center size-4 text-black dark:text-white rounded hover:bg-coolgray-400 dark:hover:bg-coolgray-300 cursor-pointer">
                            <svg class="size-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                            </svg>
                        </button>
                    </x-slot:content>
                    <livewire:project.add-empty />
                </x-modal-input>
            @endif
        </div>
        @if ($projects->count() > 0)
            <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
                @foreach ($projects as $project)
                    <div class="relative gap-2 cursor-pointer coolbox group">
                        <a href="{{ $project->navigateTo() }}" {{ wireNavigate() }} class="absolute inset-0"></a>
                        <div class="flex flex-1 mx-6">
                            <div class="flex flex-col justify-center flex-1">
                                <div class="box-title">{{ $project->name }}</div>
                                <div class="box-description">
                                    {{ $project->description }}
                                </div>
                            </div>
                            <div class="relative z-10 flex items-center justify-center gap-4 text-xs font-bold">
                                @if ($project->environments->first())
                                    @can('createAnyResource')
                                        <a class="hover:underline" {{ wireNavigate() }}
                                            href="{{ route('project.resource.create', [
                                                'project_uuid' => $project->uuid,
                                                'environment_uuid' => $project->environments->first()->uuid,
                                            ]) }}">
                                            + Add Resource
                                        </a>
                                    @endcan
                                @endif
                                @can('update', $project)
                                    <a class="hover:underline" {{ wireNavigate() }}
                                        href="{{ route('project.edit', ['project_uuid' => $project->uuid]) }}">
                                        Settings
                                    </a>
                                @endcan
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="flex flex-col gap-1">
                <div class='font-bold dark:text-warning'>No projects found.</div>
                <div class="flex items-center gap-1">
                    <x-modal-input buttonTitle="Add" title="New Project">
                        <livewire:project.add-empty />
                    </x-modal-input> your first project or
                    go to the <a class="underline dark:text-white" href="{{ route('onboarding') }}" {{ wireNavigate() }}>onboarding</a> page.
                </div>
            </div>
        @endif
    </section>

    <section>
        <div class="flex items-center gap-2 pb-2">
            <h3>Servers</h3>
            @if ($servers->count() > 0 && $privateKeys->count() > 0)
                <x-modal-input buttonTitle="Add" title="New Server" :closeOutside="false">
                    <x-slot:content>
                        <button
                            class="flex items-center justify-center size-4 text-black dark:text-white rounded hover:bg-coolgray-400 dark:hover:bg-coolgray-300 cursor-pointer">
                            <svg class="size-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                            </svg>
                        </button>
                    </x-slot:content>
                    <livewire:server.create />
                </x-modal-input>
            @endif
        </div>
        @if ($servers->count() > 0)
            <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
                @foreach ($servers as $server)
                    <a href="{{ route('server.show', ['server_uuid' => data_get($server, 'uuid')]) }}" {{ wireNavigate() }}
                        @class([
                            'gap-2 border cursor-pointer coolbox group',
                            'border-red-500' =>
                                !$server->settings->is_reachable || $server->settings->force_disabled,
                        ])>
                        <div class="flex flex-col justify-center mx-6">
                            <div class="box-title">
                                {{ $server->name }}
                            </div>
                            <div class="box-description">
                                {{ $server->description }}</div>
                            <div class="flex gap-1 text-xs text-error">
                                @if (!$server->settings->is_reachable)
                                    Not reachable
                                @endif
                                @if (!$server->settings->is_reachable && !$server->settings->is_usable)
                                    &
                                @endif
                                @if (!$server->settings->is_usable)
                                    Not usable by Coolify
                                @endif
                            </div>
                        </div>
                        <div class="flex-1"></div>
                    </a>
                @endforeach
            </div>
        @else
            @if ($privateKeys->count() === 0)
                <div class="flex flex-col gap-1">
                    <div class='font-bold dark:text-warning'>No private keys found.</div>
                    <div class="flex items-center gap-1">Before you can add your server, first <x-modal-input
                            buttonTitle="add" title="New Private Key">
                            <livewire:security.private-key.create from="server" />
                        </x-modal-input> a private key
                        or
                        go to the <a class="underline dark:text-white" href="{{ route('onboarding') }}" {{ wireNavigate() }}>onboarding</a>
                        page.
                    </div>
                </div>
            @else
                <div class="flex flex-col gap-1">
                    <div class='font-bold dark:text-warning'>No servers found.</div>
                    <div class="flex items-center gap-1">
                        <x-modal-input buttonTitle="Add" title="New Server" :closeOutside="false">
                            <livewire:server.create />
                        </x-modal-input> your first server
                        or
                        go to the <a class="underline dark:text-white" href="{{ route('onboarding') }}" {{ wireNavigate() }}>onboarding</a>
                        page.
                    </div>
                </div>
            @endif
        @endif
    </section>
</div>
