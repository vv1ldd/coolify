<div>
    @can('manageInvitations', currentTeam())
        <form wire:submit='viaLink' class="flex gap-2 flex-col lg:flex-row items-end">
            <div class="flex flex-1 lg:w-fit w-full gap-2">
                <x-forms.input id="email" type="email" label="Delivery email" name="email" placeholder="Email" required />
                <x-forms.select id="role" name="role" label="Requested role">
                    @if (auth()->user()->role() === 'owner')
                        <option value="owner">Owner</option>
                    @endif
                    <option value="admin">Admin</option>
                    <option value="member">Member</option>
                </x-forms.select>
            </div>
            <div class="flex gap-2 lg:w-fit w-full">
                <x-forms.button type="submit">Generate Discovery Pointer</x-forms.button>
                @if (is_transactional_emails_enabled())
                    <x-forms.button wire:click.prevent='viaEmail'>Send Discovery Notice</x-forms.button>
                @endif
            </div>
        </form>
    @endcan
</div>
