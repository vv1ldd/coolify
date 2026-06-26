<div>
    <x-slot:title>
        Agency Domains | Coolify
    </x-slot>

    <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
            <h1>Agency Domains</h1>
            <div class="subtitle">Owned or responsible surfaces, independent from DNS record management.</div>
        </div>
        <a {{ wireNavigate() }} class="text-sm font-bold underline" href="{{ route('agency.index') }}">Agency dashboard</a>
    </div>

    <form wire:submit.prevent="save" class="p-4 mb-6 border rounded border-neutral-200 dark:border-neutral-700">
        <h2>{{ $editingId ? 'Edit Domain Asset' : 'New Domain Asset' }}</h2>
        <div class="grid gap-3 pt-3 md:grid-cols-3">
            <label class="text-sm">
                <span class="font-bold">Domain</span>
                <input class="w-full mt-1 input" wire:model.defer="form.domain" placeholder="example.com">
                @error('form.domain') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
            </label>
            <label class="text-sm">
                <span class="font-bold">Engagement owner</span>
                <select class="w-full mt-1 input" wire:model.defer="form.engagement_uuid">
                    <option value="">No engagement owner</option>
                    @foreach ($engagements as $engagement)
                        <option value="{{ $engagement->uuid }}">{{ $engagement->client?->name }} / {{ $engagement->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm">
                <span class="font-bold">DNS zone (optional)</span>
                <select class="w-full mt-1 input" wire:model.defer="form.dns_zone_uuid">
                    <option value="">No linked DNS zone</option>
                    @foreach ($zones as $zone)
                        <option value="{{ $zone->uuid }}">{{ $zone->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm">
                <span class="font-bold">Registrar</span>
                <input class="w-full mt-1 input" wire:model.defer="form.registrar" placeholder="Namecheap">
            </label>
            <label class="text-sm">
                <span class="font-bold">Expires</span>
                <input type="date" class="w-full mt-1 input" wire:model.defer="form.expires_at">
            </label>
            <label class="text-sm">
                <span class="font-bold">Status</span>
                <select class="w-full mt-1 input" wire:model.defer="form.status">
                    @foreach ($statuses as $status)
                        <option value="{{ $status }}">{{ $status }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm md:col-span-3">
                <span class="font-bold">Ownership notes</span>
                <input class="w-full mt-1 input" wire:model.defer="form.ownership_notes" placeholder="Who controls registrar credentials, renewal responsibility, client constraints...">
            </label>
        </div>
        <div class="flex gap-2 pt-4">
            <button type="submit" class="button">{{ $editingId ? 'Update domain' : 'Create domain' }}</button>
            @if ($editingId)
                <button type="button" class="button" wire:click="resetForm">Cancel</button>
            @endif
        </div>
    </form>

    <div class="grid gap-4 xl:grid-cols-2">
        @forelse ($domains as $domain)
            <div class="p-4 border rounded border-neutral-200 dark:border-neutral-700">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="font-mono text-lg font-bold break-all">{{ $domain->domain }}</div>
                        <div class="pt-1 text-sm text-neutral-500">
                            {{ $domain->engagement?->client?->name ?: 'No client' }}
                            @if ($domain->engagement)
                                · {{ $domain->engagement->name }}
                            @endif
                        </div>
                    </div>
                    <span class="px-2 py-1 text-xs font-semibold rounded bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-100">
                        {{ $domain->status }}
                    </span>
                </div>
                <div class="flex flex-wrap gap-2 pt-3 text-xs text-neutral-500">
                    <span>{{ $domain->registrar ?: 'registrar unknown' }}</span>
                    <span>{{ $domain->expires_at ? 'expires '.$domain->expires_at->toDateString() : 'expiry unknown' }}</span>
                    <span>{{ $domain->dnsZone?->name ? 'zone '.$domain->dnsZone->name : 'no DNS zone link' }}</span>
                </div>
                @if ($domain->ownership_notes)
                    <div class="pt-2 text-sm text-neutral-500">{{ $domain->ownership_notes }}</div>
                @endif
                <div class="flex gap-2 pt-4 text-sm font-bold">
                    <button type="button" class="underline" wire:click="edit('{{ $domain->uuid }}')">Edit</button>
                    <button type="button" class="text-red-500 underline" wire:click="delete('{{ $domain->uuid }}')" wire:confirm="Delete this domain asset?">Delete</button>
                </div>
            </div>
        @empty
            <div class="p-4 border rounded border-neutral-200 dark:border-neutral-700">No agency domain assets yet.</div>
        @endforelse
    </div>
</div>
