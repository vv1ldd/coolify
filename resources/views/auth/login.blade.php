<x-layout-simple>
    <div class="min-h-screen w-full flex items-center justify-center relative bg-white dark:bg-[#090909] overflow-hidden" style="font-family: 'Space Grotesk', sans-serif;">
        <div class="absolute inset-0 pointer-events-none z-0 overflow-hidden">
            <div class="absolute top-[-10%] left-[20%] w-[60vw] h-[60vw] rounded-full blur-[80px] bg-[radial-gradient(circle,rgba(168,85,247,0.16)_0%,rgba(0,0,0,0)_70%)]"></div>
            <div class="absolute top-[30%] right-[-10%] w-[50vw] h-[50vw] rounded-full blur-[100px] bg-[radial-gradient(circle,rgba(34,211,238,0.14)_0%,rgba(0,0,0,0)_75%)]"></div>
        </div>

        <div class="relative z-10 bg-white dark:bg-[#090909] border-[4px] border-black shadow-[8px_8px_0px_#000000] rounded-xl p-8 sm:p-10 mx-4" style="width: min(460px, calc(100vw - 2rem));">
            <div class="flex items-center justify-center gap-3 mb-8">
                <div class="w-4 h-4 bg-[#a855f7] border-2 border-black rounded-[3px] shadow-[2px_2px_0px_#22d3ee]"></div>
                <h1 class="text-3xl font-black text-black dark:text-white tracking-tight uppercase m-0">Sovereign Coolify</h1>
            </div>

            <div class="text-center mb-8 space-y-3">
                <p class="text-sm font-bold text-neutral-700 dark:text-neutral-300">
                    Вход только через SL1 Identity. Coolify не хранит пароль и не становится источником вашей личности.
                </p>
                <p class="text-xs text-neutral-500 dark:text-neutral-500">
                    SL1 Connect подтвердит passkey и вернет в панель уже с проверенной identity.
                </p>
            </div>

            <div class="space-y-6">
                @if (session('status'))
                    <div class="p-4 bg-[#22d3ee]/10 border-[3px] border-[#22d3ee] rounded-lg shadow-[4px_4px_0px_#22d3ee]">
                        <p class="text-sm font-bold text-[#67e8f9]">{{ session('status') }}</p>
                    </div>
                @endif

                @if (session('error'))
                    <div class="p-4 bg-red-500/10 border-[3px] border-red-500 rounded-lg shadow-[4px_4px_0px_#f53003]">
                        <p class="text-sm font-bold text-red-600 dark:text-red-400">{{ session('error') }}</p>
                    </div>
                @endif

                @if ($errors->any())
                    <div class="p-4 bg-red-500/10 border-[3px] border-red-500 rounded-lg shadow-[4px_4px_0px_#f53003]">
                        @foreach ($errors->all() as $error)
                            <p class="text-sm font-bold text-red-600 dark:text-red-400">{{ $error }}</p>
                        @endforeach
                    </div>
                @endif

                <a href="{{ route('auth.sl1.redirect') }}" class="flex items-center justify-center gap-2 bg-[#a855f7] hover:bg-[#c084fc] text-white border-[3px] border-black shadow-[4px_4px_0px_#000000] hover:shadow-[2px_2px_0px_#22d3ee] hover:translate-y-[2px] transition-all rounded-lg py-4 px-6 font-black uppercase tracking-widest text-sm mx-auto" style="width: min(360px, 100%);">
                    Войти через SL1 Identity
                </a>

                <div class="border-t-[3px] border-dashed border-neutral-300 dark:border-neutral-800 pt-6 text-center">
                    <div class="inline-flex items-center gap-2 px-3 py-1.5 bg-[#22d3ee]/10 border-[2px] border-[#22d3ee]/50 rounded shadow-[2px_2px_0px_#0891b2]">
                        <span class="w-2 h-2 rounded-full bg-[#22d3ee]"></span>
                        <span class="text-[10px] uppercase tracking-widest font-black text-[#67e8f9]">
                            Bounded Authority Login
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-layout-simple>
