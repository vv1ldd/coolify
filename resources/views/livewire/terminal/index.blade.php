<div>
    <x-slot:title>
        Terminal | Coolify
    </x-slot>
    <div class="mt-4 pt-6 border-t-[3px] border-black">
        <h2 class="mb-4 text-xl font-black uppercase tracking-widest text-black dark:text-white" style="font-family: 'Space Grotesk', sans-serif;">System Terminal</h2>
        <div class="relative group" title="Sovereign Infrastructure Core - Terminal Access Disabled">
            <div class="absolute inset-0 bg-red-600/10 blur-xl rounded-lg opacity-50"></div>
            <div class="relative flex flex-col md:flex-row items-start md:items-center justify-between p-6 bg-white dark:bg-[#090909] border-[3px] border-black shadow-[4px_4px_0_#000000] rounded-sm">
                
                <div class="flex items-center gap-4 mb-4 md:mb-0">
                    <div class="w-12 h-12 flex items-center justify-center bg-neutral-900 border-2 border-black rounded shadow-[2px_2px_0_#000000]">
                        <svg class="w-6 h-6 text-red-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                    </div>
                    <div>
                        <div class="text-sm font-black text-black dark:text-white uppercase tracking-widest">Interactive Shell Access Disabled</div>
                        <div class="text-xs font-bold text-neutral-500 dark:text-neutral-400 mt-1">Direct server access bypasses audit logs.</div>
                    </div>
                </div>

                <div class="flex items-center gap-2 px-3 py-1.5 bg-neutral-900 border border-red-500/20 rounded-sm">
                    <div class="w-2 h-2 rounded-full bg-red-500 animate-pulse"></div>
                    <span class="text-[10px] font-bold text-red-400 uppercase tracking-widest">ACCESS DENIED</span>
                </div>
            </div>
        </div>
        <div class="mt-4 text-xs font-bold text-neutral-500 dark:text-neutral-500 leading-relaxed max-w-2xl">
            This instance is running Sovereign OS. Raw interactive bash sessions (WebSockets) have been disabled. All infrastructure modifications must be submitted as cryptographically signed Intents through the L1 Ledger orchestration layer.
        </div>
    </div>
</div>