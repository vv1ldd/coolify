<div>
    <x-slot:title>
        Agency Engagements | Coolify
    </x-slot>

    <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
            <h1>Agency Engagements</h1>
            <div class="subtitle">The commitment layer between clients and infrastructure.</div>
        </div>
        <a {{ wireNavigate() }} class="text-sm font-bold underline" href="{{ route('agency.index') }}">Agency dashboard</a>
    </div>

    <form wire:submit.prevent="save" class="p-4 mb-6 border rounded border-neutral-200 dark:border-neutral-700">
        <h2>{{ $editingId ? 'Edit Engagement' : 'New Engagement' }}</h2>
        <div class="grid gap-3 pt-3 md:grid-cols-3">
            <label class="text-sm">
                <span class="font-bold">Client</span>
                <select class="w-full mt-1 input" wire:model.defer="form.client_uuid">
                    <option value="">Select client</option>
                    @foreach ($clients as $client)
                        <option value="{{ $client->uuid }}">{{ $client->name }}</option>
                    @endforeach
                </select>
                @error('form.client_uuid') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
            </label>
            <label class="text-sm">
                <span class="font-bold">Coolify project (optional)</span>
                <select class="w-full mt-1 input" wire:model.defer="form.project_uuid">
                    <option value="">No linked project</option>
                    @foreach ($projects as $project)
                        <option value="{{ $project->uuid }}">{{ $project->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm">
                <span class="font-bold">Name</span>
                <input class="w-full mt-1 input" wire:model.defer="form.name" placeholder="Storefront launch">
                @error('form.name') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
            </label>
            <label class="text-sm">
                <span class="font-bold">Status</span>
                <select class="w-full mt-1 input" wire:model.defer="form.status">
                    @foreach ($statuses as $status)
                        <option value="{{ $status }}">{{ $status }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm">
                <span class="font-bold">Priority</span>
                <select class="w-full mt-1 input" wire:model.defer="form.priority">
                    @foreach ($priorities as $priority)
                        <option value="{{ $priority }}">{{ $priority }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm">
                <span class="font-bold">Starts</span>
                <input type="date" class="w-full mt-1 input" wire:model.defer="form.starts_at">
            </label>
            <label class="text-sm">
                <span class="font-bold">Due</span>
                <input type="date" class="w-full mt-1 input" wire:model.defer="form.due_at">
            </label>
            <label class="text-sm md:col-span-2">
                <span class="font-bold">Notes</span>
                <input class="w-full mt-1 input" wire:model.defer="form.notes" placeholder="Operational responsibility notes">
            </label>
        </div>
        <div class="flex gap-2 pt-4">
            <button type="submit" class="button">{{ $editingId ? 'Update engagement' : 'Create engagement' }}</button>
            @if ($editingId)
                <button type="button" class="button" wire:click="resetForm">Cancel</button>
            @endif
        </div>
    </form>

    <div class="grid gap-4 xl:grid-cols-2">
        @forelse ($engagements as $engagement)
            <div class="p-4 border rounded border-neutral-200 dark:border-neutral-700">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="text-lg font-bold">{{ $engagement->name }}</div>
                        <div class="pt-1 text-sm text-neutral-500">
                            {{ $engagement->client?->name ?: 'No client' }}
                            @if ($engagement->project)
                                · Project: {{ $engagement->project->name }}
                            @endif
                        </div>
                    </div>
                    <span class="px-2 py-1 text-xs font-semibold rounded bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-100">
                        {{ $engagement->status }}
                    </span>
                </div>
                <div class="flex flex-wrap gap-2 pt-3 text-xs text-neutral-500">
                    <span>priority {{ $engagement->priority }}</span>
                    <span>{{ $engagement->starts_at ? 'starts '.$engagement->starts_at->toDateString() : 'start open' }}</span>
                    <span>{{ $engagement->due_at ? 'due '.$engagement->due_at->toDateString() : 'due open' }}</span>
                </div>
                @if ($engagement->notes)
                    <div class="pt-2 text-sm text-neutral-500">{{ $engagement->notes }}</div>
                @endif
                <div class="flex gap-2 pt-4 text-sm font-bold">
                    <button type="button" class="underline" wire:click="edit('{{ $engagement->uuid }}')">Edit</button>
                    <button type="button" class="text-red-500 underline" wire:click="delete('{{ $engagement->uuid }}')" wire:confirm="Delete this engagement?">Delete</button>
                </div>
            </div>
        @empty
            <div class="p-4 border rounded border-neutral-200 dark:border-neutral-700">No agency engagements yet.</div>
        @endforelse
    </div>
</div>
