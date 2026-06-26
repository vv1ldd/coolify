<div>
    <x-slot:title>
        Agency Clients | Coolify
    </x-slot>

    <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
            <h1>Agency Clients</h1>
            <div class="subtitle">Who owns the commitment.</div>
        </div>
        <a {{ wireNavigate() }} class="text-sm font-bold underline" href="{{ route('agency.index') }}">Agency dashboard</a>
    </div>

    <form wire:submit.prevent="save" class="p-4 mb-6 border rounded border-neutral-200 dark:border-neutral-700">
        <h2>{{ $editingId ? 'Edit Client' : 'New Client' }}</h2>
        <div class="grid gap-3 pt-3 md:grid-cols-3">
            <label class="text-sm">
                <span class="font-bold">Name</span>
                <input class="w-full mt-1 input" wire:model.defer="form.name" placeholder="Acme Studio">
                @error('form.name') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
            </label>
            <label class="text-sm">
                <span class="font-bold">Contact name</span>
                <input class="w-full mt-1 input" wire:model.defer="form.contact_name" placeholder="Jonathan Montes">
            </label>
            <label class="text-sm">
                <span class="font-bold">Contact email</span>
                <input class="w-full mt-1 input" wire:model.defer="form.contact_email" placeholder="owner@example.com">
                @error('form.contact_email') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
            </label>
            <label class="text-sm">
                <span class="font-bold">Country</span>
                <input class="w-full mt-1 input" wire:model.defer="form.country" placeholder="Colombia">
            </label>
            <label class="text-sm">
                <span class="font-bold">Timezone</span>
                <input class="w-full mt-1 input" wire:model.defer="form.timezone" placeholder="America/Bogota">
            </label>
            <label class="text-sm">
                <span class="font-bold">Status</span>
                <select class="w-full mt-1 input" wire:model.defer="form.status">
                    @foreach ($statuses as $status)
                        <option value="{{ $status }}">{{ $status }}</option>
                    @endforeach
                </select>
            </label>
        </div>
        <div class="flex gap-2 pt-4">
            <button type="submit" class="button">{{ $editingId ? 'Update client' : 'Create client' }}</button>
            @if ($editingId)
                <button type="button" class="button" wire:click="resetForm">Cancel</button>
            @endif
        </div>
    </form>

    <div class="grid gap-4 xl:grid-cols-2">
        @forelse ($clients as $client)
            <div class="p-4 border rounded border-neutral-200 dark:border-neutral-700">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="text-lg font-bold">{{ $client->name }}</div>
                        <div class="pt-1 text-sm text-neutral-500">
                            {{ $client->contact_name ?: 'No contact name' }}
                            @if ($client->contact_email)
                                · {{ $client->contact_email }}
                            @endif
                        </div>
                    </div>
                    <span class="px-2 py-1 text-xs font-semibold rounded bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-100">
                        {{ $client->status }}
                    </span>
                </div>
                <div class="flex flex-wrap gap-2 pt-3 text-xs text-neutral-500">
                    <span>{{ $client->country ?: 'country unknown' }}</span>
                    <span>{{ $client->timezone ?: 'timezone unknown' }}</span>
                    <span>{{ $client->engagements_count }} engagement(s)</span>
                </div>
                <div class="flex gap-2 pt-4 text-sm font-bold">
                    <button type="button" class="underline" wire:click="edit('{{ $client->uuid }}')">Edit</button>
                    <button type="button" class="text-red-500 underline" wire:click="delete('{{ $client->uuid }}')" wire:confirm="Delete this agency client and its engagements?">Delete</button>
                </div>
            </div>
        @empty
            <div class="p-4 border rounded border-neutral-200 dark:border-neutral-700">No agency clients yet.</div>
        @endforelse
    </div>
</div>
