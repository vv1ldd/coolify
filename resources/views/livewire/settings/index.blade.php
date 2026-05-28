<div>
    <x-slot:title>
        Settings | Coolify
        </x-slot>
        <x-settings.navbar />
        <div x-data="{ activeTab: window.location.hash ? window.location.hash.substring(1) : 'general' }"
            class="flex flex-col h-full gap-8 sm:flex-row">
            <x-settings.sidebar activeMenu="general" />
            <form wire:submit='submit' class="flex flex-col">
                <div class="flex items-center gap-2">
                    <h2>General</h2>
                    <x-forms.button canGate="update" :canResource="$settings" type="submit">
                        Save
                    </x-forms.button>
                </div>
                <div class="pb-4">General configuration for your Coolify instance.</div>

                <div class="flex flex-col gap-2">
                    <div class="flex flex-wrap items-end gap-2">
                        <div class="flex gap-2 md:flex-row flex-col w-full">
                            <x-forms.input canGate="update" :canResource="$settings" id="fqdn" label="URL" readonly
                                helper="Immutable identity. The Sovereign OS domain is anchored during initial node deployment."
                                placeholder="https://coolify.yourdomain.com" />
                            <x-forms.input canGate="update" :canResource="$settings" id="instance_name" label="Name" placeholder="Sovereign Node" readonly
                                helper="Immutable identity. The node name is set during deployment." />

                        </div>

                        @if($buildActivityId)
                            <div class="w-full mt-4">
                                <livewire:activity-monitor header="Building Helper Image" :activityId="$buildActivityId"
                                    :fullHeight="false" />
                            </div>
                        @endif
                </div>
            </form>

            <x-domain-conflict-modal :conflicts="$domainConflicts" :showModal="$showDomainConflictModal"
                confirmAction="confirmDomainUsage">
                <x-slot:consequences>
                    <ul class="mt-2 ml-4 list-disc">
                        <li>The Coolify instance domain will conflict with existing resources</li>
                        <li>SSL certificates might not work correctly</li>
                        <li>Routing behavior will be unpredictable</li>
                        <li>You may not be able to access the Coolify dashboard properly</li>
                    </ul>
                </x-slot:consequences>
            </x-domain-conflict-modal>
        </div>
</div>