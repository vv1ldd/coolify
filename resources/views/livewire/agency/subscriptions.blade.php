<div>
    <x-slot:title>
        Agency Tool Subscriptions | Coolify
    </x-slot>

    <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
            <h1>Agency Tool Subscriptions</h1>
            <div class="subtitle">Recurring agency-to-vendor obligations, separate from Coolify billing.</div>
        </div>
        <a {{ wireNavigate() }} class="text-sm font-bold underline" href="{{ route('agency.index') }}">Agency dashboard</a>
    </div>

    <form wire:submit.prevent="save" class="p-4 mb-6 border rounded border-neutral-200 dark:border-neutral-700">
        <h2>{{ $editingId ? 'Edit Tool Subscription' : 'New Tool Subscription' }}</h2>
        <div class="grid gap-3 pt-3 md:grid-cols-4">
            <label class="text-sm">
                <span class="font-bold">Vendor</span>
                <input class="w-full mt-1 input" wire:model.defer="form.vendor" placeholder="GitHub">
                @error('form.vendor') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
            </label>
            <label class="text-sm">
                <span class="font-bold">Tool</span>
                <input class="w-full mt-1 input" wire:model.defer="form.tool_name" placeholder="Team plan">
                @error('form.tool_name') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
            </label>
            <label class="text-sm">
                <span class="font-bold">Engagement owner</span>
                <select class="w-full mt-1 input" wire:model.defer="form.engagement_uuid">
                    <option value="">Agency-wide</option>
                    @foreach ($engagements as $engagement)
                        <option value="{{ $engagement->uuid }}">{{ $engagement->client?->name }} / {{ $engagement->name }}</option>
                    @endforeach
                </select>
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
                <span class="font-bold">Amount</span>
                <input type="number" step="0.01" min="0" class="w-full mt-1 input" wire:model.defer="form.amount">
                @error('form.amount') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
            </label>
            <label class="text-sm">
                <span class="font-bold">Currency</span>
                <input class="w-full mt-1 input" wire:model.defer="form.currency" maxlength="3">
                @error('form.currency') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
            </label>
            <label class="text-sm">
                <span class="font-bold">Interval</span>
                <select class="w-full mt-1 input" wire:model.defer="form.interval">
                    @foreach ($intervals as $interval)
                        <option value="{{ $interval }}">{{ $interval }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm">
                <span class="font-bold">Renews</span>
                <input type="date" class="w-full mt-1 input" wire:model.defer="form.renews_at">
            </label>
            <label class="text-sm md:col-span-4">
                <span class="font-bold">Owner notes</span>
                <input class="w-full mt-1 input" wire:model.defer="form.owner_notes" placeholder="Who owns the account, cancellation constraints, client cost notes...">
            </label>
        </div>
        <div class="flex gap-2 pt-4">
            <button type="submit" class="button">{{ $editingId ? 'Update subscription' : 'Create subscription' }}</button>
            @if ($editingId)
                <button type="button" class="button" wire:click="resetForm">Cancel</button>
            @endif
        </div>
    </form>

    <div class="grid gap-4 xl:grid-cols-2">
        @forelse ($subscriptions as $subscription)
            <div class="p-4 border rounded border-neutral-200 dark:border-neutral-700">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="text-lg font-bold">{{ $subscription->vendor }} / {{ $subscription->tool_name }}</div>
                        <div class="pt-1 text-sm text-neutral-500">
                            {{ $subscription->engagement?->client?->name ?: 'Agency-wide' }}
                            @if ($subscription->engagement)
                                · {{ $subscription->engagement->name }}
                            @endif
                        </div>
                    </div>
                    <span class="px-2 py-1 text-xs font-semibold rounded bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-100">
                        {{ $subscription->status }}
                    </span>
                </div>
                <div class="flex flex-wrap gap-2 pt-3 text-xs text-neutral-500">
                    <span>{{ $subscription->currency }} {{ $subscription->amount }} / {{ $subscription->interval }}</span>
                    <span>{{ $subscription->renews_at ? 'renews '.$subscription->renews_at->toDateString() : 'renewal unknown' }}</span>
                </div>
                @if ($subscription->owner_notes)
                    <div class="pt-2 text-sm text-neutral-500">{{ $subscription->owner_notes }}</div>
                @endif
                <div class="flex gap-2 pt-4 text-sm font-bold">
                    <button type="button" class="underline" wire:click="edit('{{ $subscription->uuid }}')">Edit</button>
                    <button type="button" class="text-red-500 underline" wire:click="delete('{{ $subscription->uuid }}')" wire:confirm="Delete this tool subscription?">Delete</button>
                </div>
            </div>
        @empty
            <div class="p-4 border rounded border-neutral-200 dark:border-neutral-700">No agency tool subscriptions yet.</div>
        @endforelse
    </div>
</div>
