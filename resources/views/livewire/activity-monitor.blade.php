@php 
    use App\Actions\CoolifyTask\RunRemoteProcess; 
    $eventDigestPayload = $activity->id . $activity->created_at . $activity->description;
    $txId = $activity ? hash('sha256', $eventDigestPayload) : 'PENDING';
    $shortTxId = substr($txId, 0, 16) . '...';
@endphp
<div @class([
    'flex flex-col border-[3px] border-black bg-[#090909] shadow-[4px_4px_0_#000000] rounded-sm w-full' => true,
    'h-full overflow-hidden' => $fullHeight,
    'h-full overflow-hidden' => !$fullHeight,
])>
    @if ($activity)
        <!-- Ledger Header -->
        <div class="flex flex-col md:flex-row md:items-center justify-between border-b-[3px] border-black bg-white p-3 flex-shrink-0" @if ($isPollingActive) wire:poll.1000ms @endif>
            <div class="flex items-center gap-3">
                <div class="flex items-center justify-center w-8 h-8 bg-neutral-900 border-2 border-black shadow-[2px_2px_0_#000000] rounded">
                    @if ($isPollingActive)
                        <svg class="w-4 h-4 text-warning animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                    @else
                        <svg class="w-4 h-4 text-green-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                    @endif
                </div>
                <div>
                    <div class="text-[10px] font-black text-neutral-400 uppercase tracking-widest">
                        @if (isset($header))
                            {{ strtoupper($header) }}
                        @else
                            INTENT EXECUTION
                        @endif
                    </div>
                    <div class="text-sm font-bold text-black" title="{{ $txId }}">
                        TxID: {{ $shortTxId }}
                    </div>
                </div>
            </div>
            
            <div class="mt-2 md:mt-0 flex items-center gap-2">
                @if ($isPollingActive)
                    <div class="flex items-center gap-2 px-2 py-1 bg-warning/10 border-2 border-warning text-warning text-[10px] font-black uppercase tracking-widest rounded-sm">
                        <div class="w-1.5 h-1.5 rounded-full bg-warning animate-pulse"></div>
                        ANCHORING PENDING
                    </div>
                @else
                    <div class="flex items-center gap-2 px-2 py-1 bg-green-500/10 border-2 border-green-500 text-green-600 text-[10px] font-black uppercase tracking-widest rounded-sm">
                        <div class="w-1.5 h-1.5 rounded-full bg-green-500"></div>
                        CONSENSUS REACHED
                    </div>
                @endif
            </div>
        </div>

        <!-- Ledger Execution Stream -->
        <div x-data="{
            autoScrollEnabled: true,
            observer: null,
            scrollToBottom() {
                if (this.autoScrollEnabled) {
                    this.$el.scrollTop = this.$el.scrollHeight;
                }
            },
            isAtBottom() {
                const threshold = 5; 
                return this.$el.scrollTop + this.$el.clientHeight >= this.$el.scrollHeight - threshold;
            },
            handleScroll() {
                if (this.isAtBottom()) {
                    this.autoScrollEnabled = true;
                } else {
                    this.autoScrollEnabled = false;
                }
            }
        }" x-init="
        $nextTick(() => scrollToBottom());
        $el.addEventListener('scroll', () => handleScroll());
        observer = new MutationObserver(() => {
            $nextTick(() => scrollToBottom());
        });
        observer.observe($el, {
            childList: true,
            subtree: true,
            characterData: true
        });" x-destroy="observer && observer.disconnect()"
            @class([
                'flex flex-col w-full px-4 py-3 overflow-y-auto bg-[#090909] text-green-500 scrollbar',
                'flex-1 min-h-[300px]' => $fullHeight,
                'max-h-[500px] min-h-[200px]' => !$fullHeight,
            ])>
            <pre class="font-mono text-[11px] leading-relaxed whitespace-pre-wrap selection:bg-green-500 selection:text-black" style="text-shadow: 0 0 2px rgba(34, 197, 94, 0.4);" @if ($isPollingActive) wire:poll.1000ms="polling" @endif>{{ RunRemoteProcess::decodeOutput($activity) }}</pre>
        </div>
    @else
        @if ($showWaiting)
            <div class="flex flex-col items-center justify-center p-8 bg-white border-[3px] border-black shadow-[4px_4px_0_#000000] rounded-sm gap-4">
                <div class="w-12 h-12 flex items-center justify-center border-[3px] border-black rounded-full border-t-transparent animate-spin"></div>
                <div class="text-sm font-black text-black uppercase tracking-widest">Awaiting Network Consensus...</div>
            </div>
        @endif
    @endif
</div>
