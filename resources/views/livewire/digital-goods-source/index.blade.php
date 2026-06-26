<div>
    <x-slot:title>
        Digital Goods Source | Coolify
    </x-slot>

    <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
            <h1>Digital Goods Source</h1>
            <div class="subtitle">Provider authority runtime managed by Coolify.</div>
        </div>
    </div>

    <div class="grid gap-4 pt-4 md:grid-cols-3">
        <div class="p-4 border rounded border-neutral-200 dark:border-neutral-700">
            <div class="text-xs font-bold uppercase text-neutral-500">Runtime</div>
            <div class="pt-1 font-mono text-sm break-all">{{ data_get($status, 'url') }}</div>
            <div class="pt-2 text-sm">
                {{ data_get($status, 'reachable') ? 'reachable' : 'unreachable' }}
                · {{ data_get($status, 'ready') ? 'protocol ready' : 'not ready' }}
            </div>
        </div>
        <div class="p-4 border rounded border-neutral-200 dark:border-neutral-700">
            <div class="text-xs font-bold uppercase text-neutral-500">Expected Protocol</div>
            <div class="pt-1 font-mono text-sm">kernel {{ data_get($status, 'kernel_protocol_version') }}</div>
            <div class="pt-1 font-mono text-sm">provider {{ data_get($status, 'provider_contract_version') }}</div>
        </div>
        <div class="p-4 border rounded border-neutral-200 dark:border-neutral-700">
            <div class="text-xs font-bold uppercase text-neutral-500">Authority</div>
            <div class="pt-1 font-bold">{{ data_get($status, 'provider_authority') }}</div>
            <div class="pt-1 text-sm text-neutral-500">Provider credentials, mappings, orders and credits terminate inside Digital Goods Source.</div>
        </div>
    </div>

    <div class="p-4 mt-4 border rounded border-neutral-200 dark:border-neutral-700">
        <h2>Remote Status</h2>
        @if (data_get($status, 'remote'))
            <pre class="p-3 mt-3 overflow-auto text-xs rounded bg-neutral-100 dark:bg-neutral-900">{{ json_encode(data_get($status, 'remote'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
        @else
            <div class="pt-3 text-sm text-neutral-500">{{ data_get($status, 'error') ?: data_get($status, 'message', 'Remote status is not available yet.') }}</div>
        @endif
    </div>
</div>
