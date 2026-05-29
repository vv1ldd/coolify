<div>
    <x-slot:title>{{ data_get_str($application, 'name')->limit(10) }} > Execution Intents | Sovereign</x-slot>
    <h1>Execution Intents</h1>
    <livewire:project.shared.configuration-checker :resource="$application" />
    <livewire:project.application.heading :application="$application" />
    <div class="flex flex-col gap-2 pb-10" @if (!$skip) wire:poll.5000ms='reloadDeployments' @endif>
        <div class="flex items-end gap-2">
            <h2>Execution Intents <span class="text-xs">({{ $deployments_count }})</span></h2>
            @if ($deployments_count > 0)
                <div class="flex items-center gap-2">
                    <x-forms.button disabled="{{ !$showPrev }}" wire:click="previousPage('{{ $defaultTake }}')">
                        <svg class="w-4 h-4" viewBox="0 0 24 24">
                            <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                                stroke-width="2" d="m14 6l-6 6l6 6z" />
                        </svg>
                    </x-forms.button>
                    <span class="text-sm text-gray-600 dark:text-gray-400 px-2">
                        Page {{ $currentPage }} of {{ ceil($deployments_count / $defaultTake) }}
                    </span>
                    <x-forms.button disabled="{{ !$showNext }}" wire:click="nextPage('{{ $defaultTake }}')">
                        <svg class="w-4 h-4" viewBox="0 0 24 24">
                            <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                                stroke-width="2" d="m10 18l6-6l-6-6z" />
                        </svg>
                    </x-forms.button>
                </div>
            @endif
        </div>
        <form class="flex items-end gap-2">
            <x-forms.input id="pull_request_id" type="number" min="1" label="Pull Request Id"></x-forms.input>
            <x-forms.button type="submit">Filter</x-forms.button>
            @if ($pull_request_id)
                <x-forms.button type="button" wire:click="clearFilter">Clear</x-forms.button>
            @endif
        </form>
        @if ($pendingIntents->isNotEmpty())
            <div class="flex flex-col gap-3 border-l-2 border-warning bg-white p-4 dark:bg-coolgray-100">
                <div class="flex flex-col gap-1">
                    <div class="text-sm font-bold text-warning">Pending SL1 approvals required</div>
                    <div class="text-xs text-gray-600 dark:text-gray-400">
                        These execution intents are staged but not released. Sign the exact pending intent below to start the deployment queue.
                    </div>
                </div>
                @foreach ($pendingIntents as $intent)
                    @php
                        $rule = app(\App\Services\PolicyEngine::class)->getRule($intent->event_type);
                        $required = $rule['signatures_required'] ?? 1;
                        $collected = count($intent->signatures ?? []);
                        $latestTimeline = collect($intent->timeline ?? [])->last();
                    @endphp
                    <div class="flex flex-col gap-3 border border-warning/40 p-3 md:flex-row md:items-center md:justify-between">
                        <div class="flex flex-col gap-1 text-sm">
                            <div class="font-mono text-xs font-bold uppercase text-warning">
                                {{ str_replace('.', ' › ', $intent->event_type) }}
                            </div>
                            <div class="font-mono text-xs text-gray-600 dark:text-gray-400">
                                intent: {{ $intent->uuid }}
                            </div>
                            <div class="font-mono text-xs text-gray-600 dark:text-gray-400">
                                approvals: {{ $collected }} / {{ $required }}
                            </div>
                            <div class="font-mono text-xs text-gray-500">
                                {{ data_get($latestTimeline, 'actor', 'DID:SYS|SERVICE:#system') }} -
                                {{ data_get($latestTimeline, 'detail', 'Awaiting SL1 signature') }}
                            </div>
                        </div>
                        <div class="flex flex-col gap-2 sm:flex-row">
                            <a href="{{ route('auth.sl1.intent.redirect', ['intent' => $intent->id]) }}"
                                onclick="const popup = window.open('{{ route('auth.sl1.intent.redirect', ['intent' => $intent->id, 'popup' => 1]) }}', 'sl1_intent_{{ $intent->id }}', 'popup,width=460,height=720'); if (popup) { window.addEventListener('message', (event) => { if (event.origin === window.location.origin && event.data?.type === 'sl1:intent-signature') window.location.reload(); }, { once: true }); return false; } return true;"
                                class="inline-flex items-center justify-center rounded px-4 py-2 text-xs font-black uppercase tracking-widest text-white bg-warning hover:bg-warning/80">
                                Sign with SL1 Identity
                            </a>
                            <button wire:click="revokeIntent({{ $intent->id }})"
                                wire:confirm="Revoke this pending deployment intent? It will leave the active queue but remain in the audit trail."
                                class="inline-flex items-center justify-center rounded border border-red-500/50 px-4 py-2 text-xs font-black uppercase tracking-widest text-red-500 hover:bg-red-500 hover:text-white">
                                Revoke intent
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
        @forelse ($deployments as $deployment)
            <div @class([
                'p-2 border-l-2 bg-white dark:bg-coolgray-100',
                'border-blue-500/50 border-dashed' =>
                    data_get($deployment, 'status') === 'in_progress',
                'border-purple-500/50 border-dashed' =>
                    data_get($deployment, 'status') === 'queued',
                'border-white border-dashed' =>
                    data_get($deployment, 'status') === 'cancelled-by-user',
                'border-error' => data_get($deployment, 'status') === 'failed',
                'border-success' => data_get($deployment, 'status') === 'finished',
            ])>
                <a href="{{ $current_url . '/' . data_get($deployment, 'deployment_uuid') }}" {{ wireNavigate() }} class="block">
                    <div class="flex flex-col">
                        <div class="flex items-center gap-2 mb-2">
                            <span @class([
                                'px-3 py-1 rounded-md text-xs font-medium shadow-xs',
                                'bg-blue-100/80 text-blue-700 dark:bg-blue-500/20 dark:text-blue-300' =>
                                    data_get($deployment, 'status') === 'in_progress',
                                'bg-purple-100/80 text-purple-700 dark:bg-purple-500/20 dark:text-purple-300' =>
                                    data_get($deployment, 'status') === 'queued',
                                'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-200' =>
                                    data_get($deployment, 'status') === 'failed',
                                'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-200' =>
                                    data_get($deployment, 'status') === 'finished',
                                'bg-gray-100 text-gray-700 dark:bg-gray-600/30 dark:text-gray-300' =>
                                    data_get($deployment, 'status') === 'cancelled-by-user',
                            ])>
                                @php
                                    $statusText = match (data_get($deployment, 'status')) {
                                        'finished' => 'Success',
                                        'in_progress' => 'In Progress',
                                        'cancelled-by-user' => 'Cancelled',
                                        'queued' => 'Queued',
                                        default => ucfirst(data_get($deployment, 'status')),
                                    };
                                @endphp
                                {{ $statusText }}
                            </span>
                        </div>
                        @if (data_get($deployment, 'status') !== 'queued')
                            <div class="text-gray-600 dark:text-gray-400 text-sm">
                                Started:
                                {{ formatDateInServerTimezone(data_get($deployment, 'created_at'), data_get($application, 'destination.server')) }}
                                @if ($deployment->status !== 'in_progress' && $deployment->status !== 'cancelled-by-user')
                                    <br>Ended:
                                    {{ formatDateInServerTimezone(data_get($deployment, 'finished_at'), data_get($application, 'destination.server')) }}
                                    <br>Duration:
                                    {{ calculateDuration(data_get($deployment, 'created_at'), data_get($deployment, 'finished_at')) }}
                                    <br>Finished
                                    {{ \Carbon\Carbon::parse(data_get($deployment, 'finished_at'))->diffForHumans() }}
                                @elseif($deployment->status === 'in_progress')
                                    <br>Running for:
                                    {{ calculateDuration(data_get($deployment, 'created_at'), now()) }}
                                @endif
                            </div>
                        @endif

                        <div class="text-gray-600 dark:text-gray-400 text-sm mt-2">
                            @if (data_get($deployment, 'commit'))
                                <div x-data="{ expanded: false }">
                                    <div class="flex items-center gap-2">
                                        <span class="font-medium">Commit:</span>
                                        <a href="{{ $application->gitCommitLink(data_get($deployment, 'commit')) }}"
                                            target="_blank" class="underline">
                                            {{ substr(data_get($deployment, 'commit'), 0, 7) }}
                                        </a>
                                        @if (!$deployment->commitMessage())
                                            <span
                                                class="bg-gray-200/70 dark:bg-gray-600/20 px-2 py-0.5 rounded-md text-xs text-gray-800 dark:text-gray-100 border border-gray-400/30">
                                                @if (data_get($deployment, 'is_webhook'))
                                                    Webhook
                                                    @if (data_get($deployment, 'pull_request_id'))
                                                        | Pull Request #{{ data_get($deployment, 'pull_request_id') }}
                                                    @endif
                                                @elseif (data_get($deployment, 'pull_request_id'))
                                                    Pull Request #{{ data_get($deployment, 'pull_request_id') }}
                                                @elseif (data_get($deployment, 'rollback') === true)
                                                    Rollback
                                                @elseif (data_get($deployment, 'is_api'))
                                                    API
                                                @else
                                                    Manual
                                                @endif
                                            </span>
                                        @endif
                                        @if ($deployment->commitMessage())
                                            <span class="text-gray-600 dark:text-gray-400">-</span>
                                            <a href="{{ $application->gitCommitLink(data_get($deployment, 'commit')) }}"
                                                target="_blank"
                                                class="text-gray-600 dark:text-gray-400 truncate max-w-md underline">
                                                {{ Str::before($deployment->commitMessage(), "\n") }}
                                            </a>
                                            @if ($deployment->commitMessage() !== Str::before($deployment->commitMessage(), "\n"))
                                                <button @click="expanded = !expanded"
                                                    class="text-gray-600 dark:text-gray-400 flex items-center gap-1">
                                                    <svg x-bind:class="{ 'rotate-180': expanded }"
                                                        class="w-4 h-4 transition-transform" viewBox="0 0 24 24">
                                                        <path fill="none" stroke="currentColor"
                                                            stroke-linecap="round" stroke-linejoin="round"
                                                            stroke-width="2" d="m6 9l6 6l6-6" />
                                                    </svg>
                                                </button>
                                            @endif
                                            <span
                                                class="bg-gray-200/70 dark:bg-gray-600/20 px-2 py-0.5 rounded-md text-xs text-gray-800 dark:text-gray-100 border border-gray-400/30">
                                                @if (data_get($deployment, 'is_webhook'))
                                                    Webhook
                                                    @if (data_get($deployment, 'pull_request_id'))
                                                        | Pull Request #{{ data_get($deployment, 'pull_request_id') }}
                                                    @endif
                                                @elseif (data_get($deployment, 'pull_request_id'))
                                                    Pull Request #{{ data_get($deployment, 'pull_request_id') }}
                                                @elseif (data_get($deployment, 'rollback') === true)
                                                    Rollback
                                                @elseif (data_get($deployment, 'is_api'))
                                                    API
                                                @else
                                                    Manual
                                                @endif
                                            </span>
                                        @endif
                                    </div>
                                    @if ($deployment->commitMessage())
                                        <div x-show="expanded" x-transition:enter="transition ease-out duration-200"
                                            x-transition:enter-start="opacity-0 transform -translate-y-2"
                                            x-transition:enter-end="opacity-100 transform translate-y-0"
                                            class="mt-2 ml-4 text-gray-600 dark:text-gray-400">
                                            {{ Str::after($deployment->commitMessage(), "\n") }}
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </div>

                        @if (data_get($deployment, 'server_name') && $application->additional_servers->count() > 0)
                            <div class="text-gray-600 dark:text-gray-400 text-sm mt-2">
                                Server: {{ data_get($deployment, 'server_name') }}
                            </div>
                        @endif
                    </div>
                </a>
            </div>
        @empty
            <div>No execution intents found</div>
        @endforelse
    </div>
</div>
