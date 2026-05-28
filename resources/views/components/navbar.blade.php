<style>
    /* Sovereign sidebar menu items */
    .menu-item {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.75rem 1rem;
        color: #000000;
        font-weight: 800;
        font-size: 14px;
        border-radius: 0.5rem;
        border: 2px solid transparent;
        transition: all 0.2s ease;
        margin-bottom: 0.25rem;
    }
    
    html.dark .menu-item {
        color: #ffffff;
    }

    .menu-item:hover:not(.menu-item-active) {
        background-color: rgba(0,0,0,0.05);
        border-color: #000000;
        box-shadow: 2px 2px 0px #000000;
        transform: translateY(-1px);
    }
    
    html.dark .menu-item:hover:not(.menu-item-active) {
        background-color: rgba(255,255,255,0.05);
        border-color: #ffffff;
        box-shadow: 2px 2px 0px #ffffff;
    }

    .menu-item-active {
        background: linear-gradient(90deg, #a855f7, #7c3aed) !important;
        color: #ffffff !important;
        border: 2px solid #000000 !important;
        box-shadow: 4px 4px 0px #000000, 0 0 18px rgba(34, 211, 238, 0.18) !important;
        transform: translateY(-2px);
    }

    .menu-item-icon {
        width: 1.25rem;
        height: 1.25rem;
        stroke-width: 2.5;
    }

    /* Custom scrollbar for sidebar */
    .sidebar-scroll::-webkit-scrollbar {
        width: 4px;
    }
    .sidebar-scroll::-webkit-scrollbar-thumb {
        background: rgba(0,0,0,0.2);
        border-radius: 4px;
    }
    html.dark .sidebar-scroll::-webkit-scrollbar-thumb {
        background: rgba(255,255,255,0.2);
    }
</style>

<nav class="flex flex-col flex-1 px-4 bg-white dark:bg-[#090909] border-r-[3px] border-black shadow-[4px_0_0_#000000] z-20 relative min-h-screen"
    x-data="{
        switchWidth() {
                if (this.full === 'full') {
                    localStorage.setItem('pageWidth', 'center');
                } else {
                    localStorage.setItem('pageWidth', 'full');
                }
                window.location.reload();
            },
            setZoom(zoom) {
                localStorage.setItem('zoom', zoom);
                window.location.reload();
            },
            init() {
                this.full = localStorage.getItem('pageWidth');
                this.zoom = localStorage.getItem('zoom');
                this.queryTheme();
                this.checkZoom();
            },
            setTheme(type) {
                this.theme = 'dark';
                localStorage.setItem('theme', 'dark');
                this.queryTheme();
            },
            queryTheme() {
                localStorage.setItem('theme', 'dark');
                document.documentElement.classList.add('dark');
                document.documentElement.setAttribute('data-theme', 'dark');
                this.theme = 'dark';
            },
            checkZoom() {
                if (this.zoom === null) {
                    this.setZoom(100);
                }
                if (this.zoom === '90') {
                    const style = document.createElement('style');
                    style.textContent = `
                        html { font-size: 93.75%; }
                        :root { --vh: 1vh; }
                        @media (min-width: 1024px) { html { font-size: 87.5%; } }
                    `;
                    document.head.appendChild(style);
                }
            }
    }">
    <div class="flex lg:pt-8 pt-6 pb-6 items-start justify-between">
        <div class="flex flex-col w-full">
            <a href="/" {{ wireNavigate() }} class="flex items-center gap-2 text-2xl font-black tracking-tight text-black dark:text-white hover:opacity-80 transition-opacity uppercase" style="font-family: 'Space Grotesk', sans-serif;">
                <div class="w-4 h-4 bg-[#a855f7] border-2 border-black rounded-[3px] shadow-[2px_2px_0px_#22d3ee]"></div>
                SOVEREIGN.
            </a>
            <div class="mt-1">
                <x-version />
            </div>
            
            @if (session('sovereign_mandate_id'))
                <div class="flex items-center gap-1.5 mt-3 bg-neutral-900 border border-[#22d3ee]/20 px-2 py-1.5 rounded-sm text-[9px] font-bold text-[#67e8f9] uppercase tracking-widest" style="width: fit-content;" title="Sovereign Session Mandate ID: {{ session('sovereign_mandate_id') }}">
                    <div class="w-1.5 h-1.5 rounded-full bg-[#22d3ee] animate-pulse"></div>
                    <span>MANDATE: ACTIVE</span>
                </div>
            @else
                <div class="flex items-center gap-1.5 mt-3 bg-neutral-900 border border-[#a855f7]/20 px-2 py-1.5 rounded-sm text-[9px] font-bold text-[#c084fc] uppercase tracking-widest" style="width: fit-content;" title="Standard Session">
                    <div class="w-1.5 h-1.5 rounded-full bg-[#a855f7]"></div>
                    <span>SESSION: STANDARD</span>
                </div>
            @endif
        </div>
        <div class="flex gap-2">
            <!-- Search button that triggers global search modal -->
            <button @click="$dispatch('open-global-search')" type="button" title="Search (Press / or ⌘K)"
                class="flex items-center justify-center p-2 bg-white dark:bg-[#090909] border-[2px] border-black shadow-[2px_2px_0px_#000000] rounded-md hover:translate-y-[-1px] hover:shadow-[3px_3px_0px_#000000] transition-all">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-black dark:text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
            </button>
            <livewire:settings-dropdown />
        </div>
    </div>
    
    <div class="pb-4">
        <livewire:switch-team />
    </div>

    <ul role="list" class="flex flex-col flex-1 gap-y-7 pb-4">
        <li class="flex-1 overflow-y-auto overflow-x-hidden sidebar-scroll pr-1">
            <ul role="list" class="flex flex-col h-full">
                @if (isSubscribed() || !isCloud())
                    <!-- CORE -->
                    <div class="text-[10px] font-black text-neutral-400 uppercase tracking-widest mt-2 mb-2 px-2">Core</div>
                    <li>
                        <a title="Dashboard" href="/" {{ wireNavigate() }}
                            class="{{ request()->is('/') ? 'menu-item-active menu-item' : 'menu-item' }}">
                            <svg xmlns="http://www.w3.org/2000/svg" class="menu-item-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                            </svg>
                            <span class="menu-item-label tracking-tight">Dashboard</span>
                        </a>
                    </li>

                    <!-- GOVERNANCE -->
                    <div class="text-[10px] font-black text-neutral-400 uppercase tracking-widest mt-6 mb-2 px-2">Governance</div>
                    <li>
                        <a title="Audit Ledger" {{ wireNavigate() }}
                            class="{{ request()->is('audit-ledger*') ? 'menu-item menu-item-active' : 'menu-item' }}"
                            href="{{ route('infra.ledger.index') }}">
                            {{-- Chain / Ledger icon --}}
                            <svg xmlns="http://www.w3.org/2000/svg" class="menu-item-icon" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244" />
                            </svg>
                            <span class="menu-item-label tracking-tight">Audit Ledger</span>
                        </a>
                    </li>

                    <!-- ORCHESTRATION -->
                    <div class="text-[10px] font-black text-neutral-400 uppercase tracking-widest mt-6 mb-2 px-2">Orchestration</div>

                    <li>
                        <a title="Projects" {{ wireNavigate() }}
                            class="{{ request()->is('project/*') || request()->is('projects') ? 'menu-item menu-item-active' : 'menu-item' }}"
                            href="/projects">
                            <svg xmlns="http://www.w3.org/2000/svg" class="menu-item-icon" viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                                <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                <path d="M12 4l-8 4l8 4l8 -4l-8 -4" />
                                <path d="M4 12l8 4l8 -4" />
                                <path d="M4 16l8 4l8 -4" />
                            </svg>
                            <span class="menu-item-label tracking-tight">Projects</span>
                        </a>
                    </li>
                    <li>
                        <a title="Servers" {{ wireNavigate() }}
                            class="{{ request()->is('server/*') || request()->is('servers') ? 'menu-item menu-item-active' : 'menu-item' }}"
                            href="/servers">
                            <svg xmlns="http://www.w3.org/2000/svg" class="menu-item-icon" viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                                <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                <path d="M3 4m0 3a3 3 0 0 1 3 -3h12a3 3 0 0 1 3 3v2a3 3 0 0 1 -3 3h-12a3 3 0 0 1 -3 -3z" />
                                <path d="M15 20h-9a3 3 0 0 1 -3 -3v-2a3 3 0 0 1 3 -3h12" />
                                <path d="M7 8v.01" />
                                <path d="M7 16v.01" />
                                <path d="M20 15l-2 3h3l-2 3" />
                            </svg>
                            <span class="menu-item-label tracking-tight">Servers (Nodes)</span>
                        </a>
                    </li>

                    <!-- RESOURCES -->
                    <div class="text-[10px] font-black text-neutral-400 uppercase tracking-widest mt-6 mb-2 px-2">Resources</div>
                    <li>
                        <a title="Sources" {{ wireNavigate() }}
                            class="{{ request()->is('source*') ? 'menu-item-active menu-item' : 'menu-item' }}"
                            href="{{ route('source.all') }}">
                            <svg class="menu-item-icon" viewBox="0 0 15 15" xmlns="http://www.w3.org/2000/svg">
                                <path fill="currentColor" d="m6.793 1.207l.353.354l-.353-.354ZM1.207 6.793l-.353-.354l.353.354Zm0 1.414l.354-.353l-.354.353Zm5.586 5.586l-.354.353l.354-.353Zm1.414 0l-.353-.354l.353.354Zm5.586-5.586l.353.354l-.353-.354Zm0-1.414l-.354.353l.354-.353ZM8.207 1.207l.354-.353l-.354.353ZM6.44.854L.854 6.439l.707.707l5.585-5.585L6.44.854ZM.854 8.56l5.585 5.585l.707-.707l-5.585-5.585l-.707.707Zm7.707 5.585l5.585-5.585l-.707-.707l-5.585 5.585l.707.707Zm5.585-7.707L8.561.854l-.707.707l5.585 5.585l.707-.707Zm0 2.122a1.5 1.5 0 0 0 0-2.122l-.707.707a.5.5 0 0 1 0 .708l.707.707ZM6.44 14.146a1.5 1.5 0 0 0 2.122 0l-.707-.707a.5.5 0 0 1-.708 0l-.707.707ZM.854 6.44a1.5 1.5 0 0 0 0 2.122l.707-.707a.5.5 0 0 1 0-.708L.854 6.44Zm6.292-4.878a.5.5 0 0 1 .708 0L8.56.854a1.5 1.5 0 0 0-2.122 0l.707.707Zm-2 1.293l1 1l.708-.708l-1-1l-.708.708ZM7.5 5a.5.5 0 0 1-.5-.5H6A1.5 1.5 0 0 0 7.5 6V5Zm.5-.5a.5.5 0 0 1-.5.5v1A1.5 1.5 0 0 0 9 4.5H8ZM7.5 4a.5.5 0 0 1 .5.5h1A1.5 1.5 0 0 0 7.5 3v1Zm0-1A1.5 1.5 0 0 0 6 4.5h1a.5.5 0 0 1 .5-.5V3Zm.646 2.854l1.5 1.5l.707-.708l-1.5-1.5l-.707.708ZM10.5 8a.5.5 0 0 1-.5-.5H9A1.5 1.5 0 0 0 10.5 9V8Zm.5-.5a.5.5 0 0 1-.5.5v1A1.5 1.5 0 0 0 12 7.5h-1Zm-.5-.5a.5.5 0 0 1 .5.5h1A1.5 1.5 0 0 0 10.5 6v1Zm0-1A1.5 1.5 0 0 0 9 7.5h1a.5.5 0 0 1 .5-.5V6ZM7 5.5v4h1v-4H7Zm.5 5.5a.5.5 0 0 1-.5-.5H6A1.5 1.5 0 0 0 7.5 12v-1Zm.5-.5a.5.5 0 0 1-.5.5v1A1.5 1.5 0 0 0 9 10.5H8Zm-.5-.5a.5.5 0 0 1 .5.5h1A1.5 1.5 0 0 0 7.5 9v1Zm0-1A1.5 1.5 0 0 0 6 10.5h1a.5.5 0 0 1 .5-.5V9Z" />
                            </svg>
                            <span class="menu-item-label tracking-tight">Sources</span>
                        </a>
                    </li>
                    <li>
                        <a title="Destinations" {{ wireNavigate() }}
                            class="{{ request()->is('destination*') ? 'menu-item-active menu-item' : 'menu-item' }}"
                            href="{{ route('destination.index') }}">
                            <svg xmlns="http://www.w3.org/2000/svg" class="menu-item-icon" viewBox="0 0 24 24">
                                <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 4L3 8v12l6-3l6 3l6-4V4l-6 3l-6-3zm-2 8.001V12m4 .001V12m3-2l2 2m2 2l-2-2m0 0l2-2m-2 2l-2 2" />
                            </svg>
                            <span class="menu-item-label tracking-tight">Destinations</span>
                        </a>
                    </li>
                    <li>
                        <a title="S3 Storages" {{ wireNavigate() }}
                            class="{{ request()->is('storages*') ? 'menu-item-active menu-item' : 'menu-item' }}"
                            href="{{ route('storage.index') }}">
                            <svg xmlns="http://www.w3.org/2000/svg" class="menu-item-icon" viewBox="0 0 24 24">
                                <g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2">
                                    <path d="M4 6a8 3 0 1 0 16 0A8 3 0 1 0 4 6" />
                                    <path d="M4 6v6a8 3 0 0 0 16 0V6" />
                                    <path d="M4 12v6a8 3 0 0 0 16 0v-6" />
                                </g>
                            </svg>
                            <span class="menu-item-label tracking-tight">S3 Storages</span>
                        </a>
                    </li>

                    <!-- SECURITY & CONFIG -->
                    <div class="text-[10px] font-black text-neutral-400 uppercase tracking-widest mt-6 mb-2 px-2">Security & Config</div>
                    <li>
                        <a title="Shared variables" {{ wireNavigate() }}
                            class="{{ request()->is('shared-variables*') ? 'menu-item-active menu-item' : 'menu-item' }}"
                            href="{{ route('shared-variables.index') }}">
                            <svg xmlns="http://www.w3.org/2000/svg" class="menu-item-icon" viewBox="0 0 24 24">
                                <g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2">
                                    <path d="M5 4C2.5 9 2.5 14 5 20M19 4c2.5 5 2.5 10 0 16M9 9h1c1 0 1 1 2.016 3.527C13 15 13 16 14 16h1" />
                                    <path d="M8 16c1.5 0 3-2 4-3.5S14.5 9 16 9" />
                                </g>
                            </svg>
                            <span class="menu-item-label tracking-tight">Shared Variables</span>
                        </a>
                    </li>
                    <li>
                        <a title="Notifications" {{ wireNavigate() }}
                            class="{{ request()->is('notifications*') ? 'menu-item-active menu-item' : 'menu-item' }}"
                            href="{{ route('notifications.email') }}">
                            <svg class="menu-item-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
                                <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 5a2 2 0 1 1 4 0a7 7 0 0 1 4 6v3a4 4 0 0 0 2 3H4a4 4 0 0 0 2-3v-3a7 7 0 0 1 4-6M9 17v1a3 3 0 0 0 6 0v-1" />
                            </svg>
                            <span class="menu-item-label tracking-tight">Notifications</span>
                        </a>
                    </li>
                    <li>
                        <a title="Keys & Tokens" {{ wireNavigate() }}
                            class="{{ request()->is('security*') ? 'menu-item-active menu-item' : 'menu-item' }}"
                            href="{{ route('security.private-key.index') }}">
                            <svg class="menu-item-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m16.555 3.843l3.602 3.602a2.877 2.877 0 0 1 0 4.069l-2.643 2.643a2.877 2.877 0 0 1-4.069 0l-.301-.301l-6.558 6.558a2 2 0 0 1-1.239.578L5.172 21H4a1 1 0 0 1-.993-.883L3 20v-1.172a2 2 0 0 1 .467-1.284l.119-.13L4 17h2v-2h2v-2l2.144-2.144l-.301-.301a2.877 2.877 0 0 1 0-4.069l2.643-2.643a2.877 2.877 0 0 1 4.069 0zM15 9h.01" />
                            </svg>
                            <span class="menu-item-label tracking-tight">Keys & Tokens</span>
                        </a>
                    </li>
                    <li>
                        <a title="Tags" {{ wireNavigate() }}
                            class="{{ request()->is('tags*') ? 'menu-item-active menu-item' : 'menu-item' }}"
                            href="{{ route('tags.show') }}">
                            <svg class="menu-item-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2">
                                    <path d="M3 8v4.172a2 2 0 0 0 .586 1.414l5.71 5.71a2.41 2.41 0 0 0 3.408 0l3.592-3.592a2.41 2.41 0 0 0 0-3.408l-5.71-5.71A2 2 0 0 0 9.172 6H5a2 2 0 0 0-2 2" />
                                    <path d="m18 19l1.592-1.592a4.82 4.82 0 0 0 0-6.816L15 6m-8 4h-.01" />
                                </g>
                            </svg>
                            <span class="menu-item-label tracking-tight">Tags</span>
                        </a>
                    </li>

                    <!-- SYSTEM -->
                    <div class="text-[10px] font-black text-neutral-400 uppercase tracking-widest mt-6 mb-2 px-2">System</div>

                    <li>
                        <a title="Profile" {{ wireNavigate() }}
                            class="{{ request()->is('profile*') ? 'menu-item-active menu-item' : 'menu-item' }}"
                            href="{{ route('profile') }}">
                            <svg class="menu-item-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                                <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                <path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" />
                                <path d="M12 10m-3 0a3 3 0 1 0 6 0a3 3 0 1 0 -6 0" />
                                <path d="M6.168 18.849a4 4 0 0 1 3.832 -2.849h4a4 4 0 0 1 3.834 2.855" />
                            </svg>
                            <span class="menu-item-label tracking-tight">Profile</span>
                        </a>
                    </li>
                    <li>
                        <a title="Teams" {{ wireNavigate() }}
                            class="{{ request()->is('team*') ? 'menu-item-active menu-item' : 'menu-item' }}"
                            href="{{ route('team.index') }}">
                            <svg class="menu-item-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                                <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                <path d="M10 13a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" />
                                <path d="M8 21v-1a2 2 0 0 1 2 -2h4a2 2 0 0 1 2 2v1" />
                                <path d="M15 5a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" />
                                <path d="M17 10h2a2 2 0 0 1 2 2v1" />
                                <path d="M5 5a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" />
                                <path d="M3 13v-1a2 2 0 0 1 2 -2h2" />
                            </svg>
                            <span class="menu-item-label tracking-tight">Teams</span>
                        </a>
                    </li>
                    @if (isCloud() && auth()->user()->isAdmin())
                        <li>
                            <a title="Subscription" {{ wireNavigate() }}
                                class="{{ request()->is('subscription*') ? 'menu-item-active menu-item' : 'menu-item' }}"
                                href="{{ route('subscription.show') }}">
                                <svg class="menu-item-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
                                    <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8a3 3 0 0 1 3-3h12a3 3 0 0 1 3 3v8a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3zm0 2h18M7 15h.01M11 15h2" />
                                </svg>
                                <span class="menu-item-label tracking-tight">Subscription</span>
                            </a>
                        </li>
                    @endif
                    @if (isInstanceAdmin())
                        <li>
                            <a title="Settings" {{ wireNavigate() }}
                                class="{{ request()->is('settings*') ? 'menu-item-active menu-item' : 'menu-item' }}"
                                href="/settings">
                                <svg class="menu-item-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                                    <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                    <path d="M10.325 4.317c.426 -1.756 2.924 -1.756 3.35 0a1.724 1.724 0 0 0 2.573 1.066c1.543 -.94 3.31 .826 2.37 2.37a1.724 1.724 0 0 0 1.065 2.572c1.756 .426 1.756 2.924 0 3.35a1.724 1.724 0 0 0 -1.066 2.573c.94 1.543 -.826 3.31 -2.37 2.37a1.724 1.724 0 0 0 -2.572 1.065c-.426 1.756 -2.924 1.756 -3.35 0a1.724 1.724 0 0 0 -2.573 -1.066c-1.543 .94 -3.31 -.826 -2.37 -2.37a1.724 1.724 0 0 0 -1.065 -2.572c-1.756 -.426 -1.756 -2.924 0 -3.35a1.724 1.724 0 0 0 1.066 -2.573c-.94 -1.543 .826 -3.31 2.37 -2.37c1 .608 2.296 .07 2.572 -1.065z" />
                                    <path d="M9 12a3 3 0 1 0 6 0a3 3 0 0 0 -6 0" />
                                </svg>
                                <span class="menu-item-label tracking-tight">Settings</span>
                            </a>
                        </li>
                    @endif

                    @if (isCloud() || isDev())
                        @if (isInstanceAdmin() || session('impersonating'))
                            <li>
                                <a title="Admin" class="menu-item" href="/admin" {{ wireNavigate() }}>
                                    <svg class="text-pink-500 menu-item-icon" viewBox="0 0 256 256" xmlns="http://www.w3.org/2000/svg">
                                        <path fill="currentColor" d="M177.62 159.6a52 52 0 0 1-34 34a12.2 12.2 0 0 1-3.6.55a12 12 0 0 1-3.6-23.45a28 28 0 0 0 18.32-18.32a12 12 0 0 1 22.9 7.2ZM220 144a92 92 0 0 1-184 0c0-28.81 11.27-58.18 33.48-87.28a12 12 0 0 1 17.9-1.33l19.69 19.11L127 19.89a12 12 0 0 1 18.94-5.12C168.2 33.25 220 82.85 220 144m-24 0c0-41.71-30.61-78.39-52.52-99.29l-20.21 55.4a12 12 0 0 1-19.63 4.5L80.71 82.36C67 103.38 60 124.06 60 144a68 68 0 0 0 136 0" />
                                    </svg>
                                    <span class="menu-item-label tracking-tight">Admin</span>
                                </a>
                            </li>
                        @endif
                    @endif
                    
                    @if (isInstanceAdmin() && !isCloud())
                        @persist('upgrade')
                            <li class="mt-4">
                                <livewire:upgrade />
                            </li>
                        @endpersist
                    @endif
                @endif
                @if (!isSubscribed() && isCloud() && auth()->user()->teams()->get()->count() > 1)
                    <livewire:navbar-delete-team />
                @endif
                
                <li class="flex-1 min-h-[40px]"></li>
                
                <!-- Bottom Profile & Help Section -->
                <li class="border-t-[3px] border-black pt-4 mt-4 list-none">
                    <form action="/logout" method="POST" class="m-0">
                        @csrf
                        <button title="Logout" type="submit" class="w-full menu-item">
                            <svg class="menu-item-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path fill="currentColor" d="M12 22C6.477 22 2 17.523 2 12S6.477 2 12 2a9.985 9.985 0 0 1 8 4h-2.71a8 8 0 1 0 .001 12h2.71A9.985 9.985 0 0 1 12 22m7-6v-3h-8v-2h8V8l5 4z" />
                            </svg>
                            <span class="tracking-tight">Logout</span>
                        </button>
                    </form>
                </li>
            </ul>
        </li>
    </ul>
</nav>
