@props([
    'domains' => null,
    'teamId' => null,
])

@php
    $summaries = app(\App\Services\EdgeProtection\EdgePolicyService::class)
        ->summariesForDomains($teamId ?? currentTeam()?->id, $domains);
@endphp

@if ($summaries->isNotEmpty())
    <div class="p-3 text-xs border rounded border-neutral-200 dark:border-neutral-700">
        <div class="pb-2 font-semibold">Edge Policy</div>
        <div class="flex flex-col gap-2">
            @foreach ($summaries as $summary)
                @php($policy = $summary['policy'])
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-mono break-all">{{ $summary['domain'] }}</span>
                    <span @class([
                        'px-2 py-1 rounded font-semibold',
                        'text-neutral-700 bg-neutral-100 dark:text-neutral-100 dark:bg-neutral-800' => $policy->mode === 'off',
                        'text-green-800 bg-green-100 dark:text-green-100 dark:bg-green-800' => $policy->mode === 'normal',
                        'text-amber-800 bg-amber-100 dark:text-amber-100 dark:bg-amber-800' => $policy->mode === 'strict',
                        'text-red-800 bg-red-100 dark:text-red-100 dark:bg-red-800' => $policy->mode === 'under_attack',
                    ])>
                        {{ $policy->displayMode() }}
                    </span>
                    <span class="text-neutral-500">
                        {{ $policy->source === 'policy' ? $policy->name : 'config fallback' }}
                    </span>
                    @if ($policy->challengeEnabled || $policy->mode === 'under_attack')
                        <span class="px-2 py-1 rounded bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-100">
                            challenge
                        </span>
                    @endif
                    @if ($policy->silentDropEnabled)
                        <span class="px-2 py-1 rounded bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-100">
                            silent drop
                        </span>
                    @endif
                </div>
            @endforeach
        </div>
        <div class="pt-2 text-neutral-500">
            DNS provider settings are separate; domain protection is resolved from EdgePolicy and displayed here read-only.
        </div>
    </div>
@endif
