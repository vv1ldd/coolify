<div>
    <x-slot:title>
        Advanced Settings | Coolify
        </x-slot>
        <x-settings.navbar />
        <div x-data="{ activeTab: window.location.hash ? window.location.hash.substring(1) : 'general' }"
            class="flex flex-col h-full gap-8 sm:flex-row">
            <x-settings.sidebar activeMenu="advanced" />
            <form wire:submit='submit' class="flex flex-col w-full">
                <div class="flex items-center gap-2">
                    <h2>Advanced</h2>
                    <x-forms.button type="submit">
                        Save
                    </x-forms.button>
                </div>
                <div class="pb-4">Advanced settings for your Coolify instance.</div>

                <div class="flex flex-col gap-1">

                    <h4 class="pt-4">DNS Settings</h4>
                    <div class="md:w-96">
                        <x-forms.checkbox instantSave id="is_dns_validation_enabled"
                            helper="Verify that custom domains are correctly configured in DNS before deployment. Prevents deployment failures from DNS misconfigurations."
                            label="DNS Validation" />
                    </div>

                    <x-forms.input id="custom_dns_servers" label="Custom DNS Servers"
                        helper="Custom DNS servers for domain validation. Comma-separated list (e.g., 1.1.1.1,8.8.8.8). Leave empty to use system defaults."
                        placeholder="1.1.1.1,8.8.8.8" />
                    <h4 class="pt-4">API Settings</h4>
                    <div class="md:w-96">
                        <x-forms.checkbox instantSave id="is_api_enabled" label="API Access"
                            helper="If enabled, authenticated requests to Coolify's REST API will be allowed. Configure API tokens in Security > API Tokens." />
                    </div>
                    <x-forms.input id="allowed_ips" label="Allowed IPs for API Access"
                        helper="Allowed IP addresses or subnets for API access.<br>Supports single IPs (192.168.1.100) and CIDR notation (192.168.1.0/24).<br>Use comma to separate multiple entries.<br>Use 0.0.0.0 or leave empty to allow from anywhere."
                        placeholder="192.168.1.100,10.0.0.0/8,203.0.113.0/24" />

                    <h4 class="pt-4">UI Settings</h4>
                    <div class="md:w-96">
                        <x-forms.checkbox instantSave id="is_wire_navigate_enabled" label="SPA Navigation"
                            helper="Enable single-page application (SPA) style navigation with prefetching on hover. When enabled, page transitions are smoother without full page reloads and pages are prefetched when hovering over links. Disable if you experience navigation issues." />
                    </div>

                </div>
                <div class="flex flex-col gap-1">
                    <h4 class="pt-4">SL1 Intent Approval</h4>
                    <div class="pb-4 md:w-96">
                        <x-forms.checkbox id="disable_two_step_confirmation" label="Legacy Confirmation Disabled"
                            disabled
                            helper="Sovereign Coolify does not use password or text-based two-step confirmation as an authority source. Destructive operations must be promoted to SL1 Identity signed intents." />
                    </div>
                    <x-callout type="info" title="Constitutional confirmation model" class="mb-4">
                        Password prompts and typed confirmation strings are legacy friction, not authority.
                        The SL1 path is SignedIntent approval over the operation, actor, resource, and current ledger
                        context.
                    </x-callout>
                </div>
            </form>
        </div>
</div>
