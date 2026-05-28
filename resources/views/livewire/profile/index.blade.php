<div>
    <x-slot:title>
        Profile | Coolify
    </x-slot>
    <h1>Profile</h1>
    <div class="subtitle -mt-2">Your user profile settings.</div>
    <form wire:submit='submit' class="flex flex-col">
        <div class="flex items-center gap-2">
            <h2>General</h2>
            <x-forms.button type="submit" label="Save">Save</x-forms.button>
        </div>
        <div class="flex flex-col gap-2 lg:flex-row items-end">
            <x-forms.input id="name" label="Name" required />
            <x-forms.input id="email" label="Email" readonly />
            <x-forms.button type="button" disabled>Managed by SL1 Identity</x-forms.button>
        </div>
        <div class="pt-2 text-xs font-bold dark:text-warning">
            Email is contact metadata projected from SL1 Identity. It cannot mutate identity authority.
        </div>
    </form>
    <div class="mt-8 pt-6 border-t-[3px] border-black">
        <h2 class="mb-4 text-xl font-black uppercase tracking-widest text-black dark:text-white" style="font-family: 'Space Grotesk', sans-serif;">Sovereign Identity</h2>
        <div class="relative group" title="Sovereign Infrastructure Core - Identity Managed by L1 Ledger">
            <div class="absolute inset-0 bg-purple-600/10 blur-xl rounded-lg opacity-50"></div>
            <div class="relative flex flex-col md:flex-row items-start md:items-center justify-between p-6 bg-white dark:bg-[#090909] border-[3px] border-black shadow-[4px_4px_0_#000000] rounded-sm">

                <div class="flex items-center gap-4 mb-4 md:mb-0">
                    <div class="w-12 h-12 flex items-center justify-center bg-neutral-900 border-2 border-black rounded shadow-[2px_2px_0_#000000]">
                        <svg class="w-6 h-6 text-purple-500" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M12 2C6.48 2 2 6.48 2 12C2 17.52 6.48 22 12 22C17.52 22 22 17.52 22 12C22 6.48 17.52 2 12 2ZM11 19.93C7.05 19.43 4 16.05 4 12C4 7.95 7.05 4.57 11 4.07V19.93ZM13 4.07C16.95 4.57 20 7.95 20 12C20 16.05 16.95 19.43 13 19.93V4.07Z" fill="currentColor"/>
                        </svg>
                    </div>
                    <div>
                        <div class="text-sm font-black text-black dark:text-white uppercase tracking-widest">Passkey & L1 Authentication Active</div>
                        <div class="text-xs font-bold text-neutral-500 dark:text-neutral-400 mt-1">Passwords and OTP are disabled.</div>
                    </div>
                </div>

                <div class="flex items-center gap-2 px-3 py-1.5 bg-neutral-900 border border-purple-500/20 rounded-sm">
                    <div class="w-2 h-2 rounded-full bg-purple-500 animate-pulse"></div>
                    <span class="text-[10px] font-bold text-purple-400 uppercase tracking-widest">Cryptographic Proof Verified</span>
                </div>
            </div>
        </div>
        <div class="mt-4 text-xs font-bold text-neutral-500 dark:text-neutral-500 leading-relaxed max-w-2xl">
            This instance is running Sovereign OS. Standard password authentication and Two-Factor Authentication (OTP) have been stripped from the kernel. All identity verification is now anchored to your device Passkeys and L1 cryptographic proofs.
        </div>
    </div>
    @if (session()->has('errors'))
        <div class="text-error">
            Something went wrong. Please try again.
        </div>
    @endif
</div>
