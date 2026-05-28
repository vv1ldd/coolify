<x-layout-simple>
    <div class="min-h-screen w-full flex items-center justify-center relative bg-white dark:bg-[#090909] overflow-hidden" style="font-family: 'Space Grotesk', sans-serif;">
        <div class="absolute inset-0 pointer-events-none z-0 overflow-hidden">
            <div class="absolute top-[-10%] left-[20%] w-[60vw] h-[60vw] rounded-full blur-[80px] bg-[radial-gradient(circle,rgba(245,48,3,0.06)_0%,rgba(0,0,0,0)_70%)]"></div>
            <div class="absolute top-[30%] right-[-10%] w-[50vw] h-[50vw] rounded-full blur-[100px] bg-[radial-gradient(circle,rgba(79,70,229,0.06)_0%,rgba(0,0,0,0)_75%)]"></div>
        </div>

        <div class="relative z-10 bg-white dark:bg-[#090909] border-[4px] border-black shadow-[8px_8px_0px_#000000] rounded-xl p-8 sm:p-10 mx-4" style="width: min(460px, calc(100vw - 2rem));">
            <div class="flex items-center justify-center gap-3 mb-8">
                <div class="w-4 h-4 bg-[#f53003] border-2 border-black rounded-[3px] shadow-[2px_2px_0px_#000000]"></div>
                <h1 class="text-3xl font-black text-black dark:text-white tracking-tight uppercase m-0">Sovereign Coolify</h1>
            </div>

            <div class="text-center mb-8 space-y-3">
                @if ($isFirstUser)
                    <p class="text-sm font-bold text-neutral-700 dark:text-neutral-300">
                        Первая SL1 Identity станет root-администратором этого Coolify узла.
                    </p>
                @else
                    <p class="text-sm font-bold text-neutral-700 dark:text-neutral-300">
                        Новые пользователи добавляются только через проверенную SL1 Identity.
                    </p>
                @endif
                <p class="text-xs text-neutral-500 dark:text-neutral-500">
                    Парольная регистрация отключена. Authority приходит из SL1 proof, а не из формы.
                </p>
            </div>

            <div class="space-y-6">
                @if ($errors->any())
                    <div class="p-4 bg-red-500/10 border-[3px] border-red-500 rounded-lg shadow-[4px_4px_0px_#f53003]">
                        @foreach ($errors->all() as $error)
                            <p class="text-sm font-bold text-red-600 dark:text-red-400">{{ $error }}</p>
                        @endforeach
                    </div>
                @endif

                <a href="{{ route('auth.sl1.redirect') }}" class="flex items-center justify-center gap-2 bg-[#f53003] hover:bg-[#ff451a] text-white border-[3px] border-black shadow-[4px_4px_0px_#000000] hover:shadow-[2px_2px_0px_#000000] hover:translate-y-[2px] transition-all rounded-lg py-4 px-6 font-black uppercase tracking-widest text-sm mx-auto" style="width: min(360px, 100%);">
                    Создать через SL1 Identity
                </a>

                <a href="{{ route('login') }}" class="block text-center text-xs font-bold uppercase tracking-widest text-neutral-500 hover:text-neutral-900 dark:hover:text-white">
                    Уже есть SL1 Identity? Войти
                </a>
            </div>
        </div>
    </div>
</x-layout-simple>
