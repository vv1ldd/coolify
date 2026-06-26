<div>
    <x-slot:title>
        Agency Control Center | Coolify
    </x-slot>

    <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
            <h1>Agency Control Center</h1>
            <div class="subtitle">Commitment authority: who is responsible for what.</div>
        </div>
    </div>

    <div class="flex flex-wrap gap-2 pb-4 text-sm font-semibold">
        <a {{ wireNavigate() }} class="underline" href="{{ route('agency.clients') }}">Clients</a>
        <a {{ wireNavigate() }} class="underline" href="{{ route('agency.engagements') }}">Engagements</a>
        <a {{ wireNavigate() }} class="underline" href="{{ route('agency.domains') }}">Domains</a>
        <a {{ wireNavigate() }} class="underline" href="{{ route('agency.subscriptions') }}">Tool Subscriptions</a>
    </div>

    <div class="grid gap-4 md:grid-cols-4">
        <div class="p-4 border rounded border-neutral-200 dark:border-neutral-700">
            <div class="text-xs font-bold uppercase text-neutral-500">Who</div>
            <div class="pt-1 text-3xl font-black">{{ data_get($summary, 'clients_count', 0) }}</div>
            <div class="pt-1 text-sm text-neutral-500">Clients</div>
        </div>
        <div class="p-4 border rounded border-neutral-200 dark:border-neutral-700">
            <div class="text-xs font-bold uppercase text-neutral-500">What</div>
            <div class="pt-1 text-3xl font-black">{{ data_get($summary, 'open_engagements_count', 0) }}</div>
            <div class="pt-1 text-sm text-neutral-500">Open engagements</div>
        </div>
        <div class="p-4 border rounded border-neutral-200 dark:border-neutral-700">
            <div class="text-xs font-bold uppercase text-neutral-500">Where</div>
            <div class="pt-1 text-3xl font-black">{{ data_get($summary, 'domains_count', 0) }}</div>
            <div class="pt-1 text-sm text-neutral-500">Domain assets</div>
        </div>
        <div class="p-4 border rounded border-neutral-200 dark:border-neutral-700">
            <div class="text-xs font-bold uppercase text-neutral-500">Cost</div>
            <div class="pt-1 text-3xl font-black">{{ data_get($summary, 'active_subscriptions_count', 0) }}</div>
            <div class="pt-1 text-sm text-neutral-500">Active tool subscriptions</div>
        </div>
    </div>

    <div class="grid gap-4 pt-6 xl:grid-cols-3">
        <div class="p-4 border rounded border-neutral-200 dark:border-neutral-700">
            <h2>Engagement Status</h2>
            <div class="flex flex-col gap-2 pt-3 text-sm">
                @forelse (data_get($summary, 'engagements_by_status', collect()) as $status => $total)
                    <div class="flex justify-between gap-3">
                        <span class="font-mono">{{ $status }}</span>
                        <span class="font-bold">{{ $total }}</span>
                    </div>
                @empty
                    <div class="text-neutral-500">No engagements recorded yet.</div>
                @endforelse
            </div>
        </div>

        <div class="p-4 border rounded border-neutral-200 dark:border-neutral-700">
            <h2>Domain Attention</h2>
            <div class="flex flex-col gap-2 pt-3 text-sm">
                @forelse (data_get($summary, 'domain_alerts', collect()) as $domain)
                    <div class="p-2 border rounded border-neutral-100 dark:border-neutral-800">
                        <div class="font-mono font-bold break-all">{{ $domain->domain }}</div>
                        <div class="text-xs text-neutral-500">
                            {{ $domain->expires_at ? 'expires '.$domain->expires_at->toDateString() : 'expiry unknown' }}
                            · {{ $domain->engagement?->name ?: 'no engagement owner' }}
                        </div>
                    </div>
                @empty
                    <div class="text-neutral-500">No domain alerts.</div>
                @endforelse
            </div>
        </div>

        <div class="p-4 border rounded border-neutral-200 dark:border-neutral-700">
            <h2>Subscription Attention</h2>
            <div class="flex flex-col gap-2 pt-3 text-sm">
                @forelse (data_get($summary, 'subscription_alerts', collect()) as $subscription)
                    <div class="p-2 border rounded border-neutral-100 dark:border-neutral-800">
                        <div class="font-bold">{{ $subscription->vendor }} / {{ $subscription->tool_name }}</div>
                        <div class="text-xs text-neutral-500">
                            {{ $subscription->renews_at ? 'renews '.$subscription->renews_at->toDateString() : 'renewal unknown' }}
                            · {{ $subscription->engagement?->name ?: 'no engagement owner' }}
                        </div>
                    </div>
                @empty
                    <div class="text-neutral-500">No subscription alerts.</div>
                @endforelse
            </div>
        </div>
    </div>

    <div class="p-4 mt-6 border rounded border-neutral-200 dark:border-neutral-700">
        <h2>Responsibility Map</h2>
        <div class="grid gap-3 pt-3 md:grid-cols-2 xl:grid-cols-4">
            @forelse (data_get($summary, 'client_responsibility', collect()) as $client)
                <div class="p-3 border rounded border-neutral-100 dark:border-neutral-800">
                    <div class="font-bold">{{ $client->name }}</div>
                    <div class="pt-1 text-sm text-neutral-500">{{ $client->open_engagements_count }} open / {{ $client->engagements_count }} total engagements</div>
                </div>
            @empty
                <div class="text-sm text-neutral-500">No clients recorded yet.</div>
            @endforelse
        </div>
    </div>
</div>
