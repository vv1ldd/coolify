<?php

namespace App\Livewire\Server;

use App\Actions\Server\StartSentinel;
use App\Actions\Server\StopSentinel;
use App\Enums\ProtectionActionType;
use App\Events\ServerReachabilityChanged;
use App\Models\CloudProviderToken;
use App\Models\Server;
use App\Rules\ValidServerIp;
use App\Services\HetznerService;
use App\Services\IncidentProtection\ProtectionAction;
use App\Services\IncidentProtection\ProtectionActionExecutor;
use App\Services\Provider\ProviderServerActionAdapterFactory;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Show extends Component
{
    use AuthorizesRequests;

    public Server $server;

    public string $name;

    public ?string $description = null;

    public string $ip;

    public string $user;

    public string $port;

    public ?string $validationLogs = null;

    public ?string $wildcardDomain = null;

    public bool $isReachable;

    public bool $isUsable;

    public bool $isSovereignShielded = false;

    public bool $isSwarmManager;

    public bool $isSwarmWorker;

    public bool $isBuildServer;

    #[Locked]
    public bool $isBuildServerLocked = false;

    public bool $isMetricsEnabled;

    public string $sentinelToken;

    public ?string $sentinelUpdatedAt = null;

    public int $sentinelMetricsRefreshRateSeconds;

    public int $sentinelMetricsHistoryDays;

    public int $sentinelPushIntervalSeconds;

    public ?string $sentinelCustomUrl = null;

    public bool $isSentinelEnabled;

    public bool $isSentinelDebugEnabled;

    public ?string $sentinelCustomDockerImage = null;

    public string $serverTimezone;

    public ?string $hetznerServerStatus = null;

    public bool $hetznerServerManuallyStarted = false;

    public bool $isValidating = false;

    // Hetzner linking properties
    public Collection $availableHetznerTokens;

    public ?int $selectedHetznerTokenId = null;

    public ?string $manualHetznerServerId = null;

    public ?array $matchedHetznerServer = null;

    public ?string $hetznerSearchError = null;

    public bool $hetznerNoMatchFound = false;

    public Collection $availableProviderControlTokens;

    public ?int $selectedProviderControlTokenId = null;

    public ?string $providerControlServerId = null;

    public ?array $providerControlStatus = null;

    public ?array $providerControlActionPlan = null;

    public ?string $providerControlMessage = null;

    public ?string $providerControlError = null;

    public function getListeners()
    {
        $teamId = $this->server->team_id ?? auth()->user()->currentTeam()->id;

        return [
            'refreshServerShow' => 'refresh',
            'refreshServer' => '$refresh',
            "echo-private:team.{$teamId},SentinelRestarted" => 'handleSentinelRestarted',
            "echo-private:team.{$teamId},ServerValidated" => 'handleServerValidated',
        ];
    }

    protected function rules(): array
    {
        return [
            'name' => ValidationPatterns::nameRules(),
            'description' => ValidationPatterns::descriptionRules(),
            'ip' => ['required', new ValidServerIp],
            'user' => ['required', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'port' => 'required|integer|between:1,65535',
            'validationLogs' => 'nullable',
            'wildcardDomain' => 'nullable|url',
            'isReachable' => 'required',
            'isUsable' => 'required',
            'isSwarmManager' => 'required',
            'isSwarmWorker' => 'required',
            'isBuildServer' => 'required',
            'isMetricsEnabled' => 'required',
            'sentinelToken' => 'required',
            'sentinelUpdatedAt' => 'nullable',
            'sentinelMetricsRefreshRateSeconds' => 'required|integer|min:1',
            'sentinelMetricsHistoryDays' => 'required|integer|min:1',
            'sentinelPushIntervalSeconds' => 'required|integer|min:10',
            'sentinelCustomUrl' => 'nullable|url',
            'isSentinelEnabled' => 'required',
            'isSentinelDebugEnabled' => 'required',
            'serverTimezone' => 'required',
        ];
    }

    protected function messages(): array
    {
        return array_merge(
            ValidationPatterns::combinedMessages(),
            [
                'ip.required' => 'The IP Address field is required.',
                'user.required' => 'The User field is required.',
                'port.required' => 'The Port field is required.',
                'wildcardDomain.url' => 'The Wildcard Domain must be a valid URL.',
                'sentinelToken.required' => 'The Sentinel Token field is required.',
                'sentinelMetricsRefreshRateSeconds.required' => 'The Metrics Refresh Rate field is required.',
                'sentinelMetricsRefreshRateSeconds.integer' => 'The Metrics Refresh Rate must be an integer.',
                'sentinelMetricsRefreshRateSeconds.min' => 'The Metrics Refresh Rate must be at least 1 second.',
                'sentinelMetricsHistoryDays.required' => 'The Metrics History Days field is required.',
                'sentinelMetricsHistoryDays.integer' => 'The Metrics History Days must be an integer.',
                'sentinelMetricsHistoryDays.min' => 'The Metrics History Days must be at least 1 day.',
                'sentinelPushIntervalSeconds.required' => 'The Push Interval field is required.',
                'sentinelPushIntervalSeconds.integer' => 'The Push Interval must be an integer.',
                'sentinelPushIntervalSeconds.min' => 'The Push Interval must be at least 10 seconds.',
                'sentinelCustomUrl.url' => 'The Custom Sentinel URL must be a valid URL.',
                'serverTimezone.required' => 'The Server Timezone field is required.',
            ]
        );
    }

    public function mount(string $server_uuid)
    {
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
            $this->syncData();
            if (! $this->server->isEmpty()) {
                $this->isBuildServerLocked = true;
            }
            // Load saved Hetzner status and validation state
            $this->hetznerServerStatus = $this->server->hetzner_server_status;
            $this->isValidating = $this->server->is_validating ?? false;

            // Load Hetzner tokens for linking
            $this->loadHetznerTokens();
            $this->loadProviderControlBinding();

            // Check Sovereign Node Protection status
            $this->checkSovereignShieldStatus();

        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    #[Computed]
    public function timezones(): array
    {
        return collect(timezone_identifiers_list())
            ->sort()
            ->values()
            ->toArray();
    }

    public function syncData(bool $toModel = false)
    {
        if ($toModel) {
            $this->validate();

            $this->authorize('update', $this->server);
            $foundServer = Server::where('ip', $this->ip)
                ->where('id', '!=', $this->server->id)
                ->first();
            if ($foundServer) {
                $this->ip = $this->server->ip;
                if ($foundServer->team_id === currentTeam()->id) {
                    throw new \Exception('A server with this IP/Domain already exists in your team.');
                }

                throw new \Exception('A server with this IP/Domain is already in use by another team.');
            }

            $this->server->name = $this->name;
            $this->server->description = $this->description;
            $this->server->ip = $this->ip;
            $this->server->user = $this->user;
            $this->server->port = $this->port;
            $this->server->validation_logs = $this->validationLogs;
            $this->server->save();

            $this->server->settings->is_swarm_manager = $this->isSwarmManager;
            $this->server->settings->wildcard_domain = $this->wildcardDomain;
            $this->server->settings->is_swarm_worker = $this->isSwarmWorker;
            $this->server->settings->is_build_server = $this->isBuildServer;
            $this->server->settings->is_metrics_enabled = $this->isMetricsEnabled;
            $this->server->settings->sentinel_token = $this->sentinelToken;
            $this->server->settings->sentinel_metrics_refresh_rate_seconds = $this->sentinelMetricsRefreshRateSeconds;
            $this->server->settings->sentinel_metrics_history_days = $this->sentinelMetricsHistoryDays;
            $this->server->settings->sentinel_push_interval_seconds = $this->sentinelPushIntervalSeconds;
            $this->server->settings->sentinel_custom_url = $this->sentinelCustomUrl;
            $this->server->settings->is_sentinel_enabled = $this->isSentinelEnabled;
            $this->server->settings->is_sentinel_debug_enabled = $this->isSentinelDebugEnabled;

            if (! validate_timezone($this->serverTimezone)) {
                $this->serverTimezone = config('app.timezone');
                throw new \Exception('Invalid timezone.');
            } else {
                $this->server->settings->server_timezone = $this->serverTimezone;
            }

            $this->server->settings->save();
        } else {
            $this->name = $this->server->name;
            $this->description = $this->server->description;
            $this->ip = $this->server->ip;
            $this->user = $this->server->user;
            $this->port = $this->server->port;

            $this->wildcardDomain = $this->server->settings->wildcard_domain;
            $this->isReachable = $this->server->settings->is_reachable;
            $this->isUsable = $this->server->settings->is_usable;
            $this->isSwarmManager = $this->server->settings->is_swarm_manager;
            $this->isSwarmWorker = $this->server->settings->is_swarm_worker;
            $this->isBuildServer = $this->server->settings->is_build_server;
            $this->isMetricsEnabled = $this->server->settings->is_metrics_enabled;
            $this->sentinelToken = $this->server->settings->sentinel_token;
            $this->sentinelMetricsRefreshRateSeconds = $this->server->settings->sentinel_metrics_refresh_rate_seconds;
            $this->sentinelMetricsHistoryDays = $this->server->settings->sentinel_metrics_history_days;
            $this->sentinelPushIntervalSeconds = $this->server->settings->sentinel_push_interval_seconds;
            $this->sentinelCustomUrl = $this->server->settings->sentinel_custom_url;
            $this->isSentinelEnabled = $this->server->settings->is_sentinel_enabled;
            $this->isSentinelDebugEnabled = $this->server->settings->is_sentinel_debug_enabled;
            $this->sentinelUpdatedAt = $this->server->sentinel_updated_at;
            $this->serverTimezone = $this->server->settings->server_timezone;
            $this->isValidating = $this->server->is_validating ?? false;
        }
    }

    public function refresh()
    {
        $this->syncData();
        $this->loadProviderControlBinding();
        $this->checkSovereignShieldStatus();
    }

    public function checkSovereignShieldStatus()
    {
        try {
            if ($this->server->isFunctional()) {
                $output = instant_remote_process(['test -f /etc/sysctl.d/99-wildflow-hardened.conf && echo "SHIELDED" || echo "UNSHIELDED"'], $this->server, false);
                $this->isSovereignShielded = trim($output) === 'SHIELDED';
            }
        } catch (\Throwable $e) {
            // Silently fail if not reachable yet
        }
    }

    public function shieldSovereignServer()
    {
        try {
            $this->authorize('update', $this->server);

            $bashScript = <<<'BASH'
export DEBIAN_FRONTEND=noninteractive
apt-get update -y > /dev/null
apt-get install -y ufw fail2ban unattended-upgrades jq > /dev/null

cat << 'EOF' > /etc/sysctl.d/99-wildflow-hardened.conf
net.ipv4.tcp_syncookies = 1
net.ipv4.conf.all.rp_filter = 1
net.ipv4.conf.default.rp_filter = 1
net.ipv4.conf.all.accept_source_route = 0
net.ipv4.conf.all.accept_redirects = 0
net.ipv4.conf.all.secure_redirects = 0
fs.protected_hardlinks = 1
fs.protected_symlinks = 1
EOF
sysctl -p /etc/sysctl.d/99-wildflow-hardened.conf > /dev/null || true

ufw --force reset > /dev/null
ufw default deny incoming > /dev/null
ufw default allow outgoing > /dev/null
ufw allow 22/tcp > /dev/null
ufw allow 80/tcp > /dev/null
ufw allow 443/tcp > /dev/null
ufw --force enable > /dev/null

cat << 'EOF' > /etc/fail2ban/jail.d/wildflow.local
[sshd]
enabled = true
port = 22
filter = sshd
logpath = /var/log/auth.log
maxretry = 3
findtime = 600
bantime = 3600
EOF
systemctl restart fail2ban > /dev/null || true
systemctl enable fail2ban > /dev/null || true
systemctl enable unattended-upgrades > /dev/null || true
systemctl start unattended-upgrades > /dev/null || true

mkdir -p /etc/docker
if [ ! -f /etc/docker/daemon.json ]; then
  echo '{"log-driver": "json-file", "log-opts": {"max-size": "10m", "max-file": "3"}}' > /etc/docker/daemon.json
else
  cat /etc/docker/daemon.json | jq '. + {"log-driver": "json-file", "log-opts": {"max-size": "10m", "max-file": "3"}}' > /etc/docker/daemon.json.tmp && mv /etc/docker/daemon.json.tmp /etc/docker/daemon.json
fi
systemctl reload docker > /dev/null || systemctl restart docker > /dev/null || true
BASH;

            $encoded = base64_encode($bashScript);
            instant_remote_process(["echo {$encoded} | base64 -d | bash"], $this->server, false);

            $this->isSovereignShielded = true;
            $this->dispatch('success', 'Sovereign Node Shield deployed successfully! Node is now hardened.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function handleSentinelRestarted($event)
    {
        // Only refresh if the event is for this server
        if (isset($event['serverUuid']) && $event['serverUuid'] === $this->server->uuid) {
            $this->server->refresh();
            $this->syncData();
            $this->dispatch('success', 'Sentinel has been restarted successfully.');
        }
    }

    public function validateServer($install = true)
    {
        try {
            $this->authorize('update', $this->server);
            $this->validationLogs = $this->server->validation_logs = null;
            $this->server->save();
            $this->dispatch('init', $install);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function checkLocalhostConnection()
    {
        $this->syncData(true);
        ['uptime' => $uptime, 'error' => $error] = $this->server->validateConnection();
        if ($uptime) {
            $this->dispatch('success', 'Server is reachable.');
            $this->server->settings->is_reachable = $this->isReachable = true;
            $this->server->settings->is_usable = $this->isUsable = true;
            $this->server->settings->save();
            ServerReachabilityChanged::dispatch($this->server);
        } else {
            $this->dispatch('error', 'Server is not reachable.', 'Please validate your configuration and connection.<br><br>Check this <a target="_blank" class="underline" href="https://coolify.io/docs/knowledge-base/server/openssh">documentation</a> for further help. <br><br>Error: '.$error);

            return;
        }
    }

    public function restartSentinel()
    {
        try {
            $this->authorize('manageSentinel', $this->server);
            $customImage = isDev() ? $this->sentinelCustomDockerImage : null;
            $this->server->restartSentinel($customImage);
            $this->dispatch('info', 'Restarting Sentinel.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }

    }

    public function updatedIsSentinelDebugEnabled($value)
    {
        try {
            $this->submit();
            $this->restartSentinel();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function updatedIsMetricsEnabled($value)
    {
        try {
            $this->submit();
            $this->restartSentinel();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function updatedIsBuildServer($value)
    {
        try {
            $this->authorize('update', $this->server);
            if ($value === true && $this->isSentinelEnabled) {
                $this->isSentinelEnabled = false;
                $this->isMetricsEnabled = false;
                $this->isSentinelDebugEnabled = false;
                StopSentinel::dispatch($this->server);
                $this->dispatch('info', 'Sentinel has been disabled as build servers cannot run Sentinel.');
            }
            $this->submit();
            // Dispatch event to refresh the navbar
            $this->dispatch('refreshServerShow');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function updatedIsSentinelEnabled($value)
    {
        try {
            $this->authorize('manageSentinel', $this->server);
            if ($value === true) {
                if ($this->isBuildServer) {
                    $this->isSentinelEnabled = false;
                    $this->dispatch('error', 'Sentinel cannot be enabled on build servers.');

                    return;
                }
                $customImage = isDev() ? $this->sentinelCustomDockerImage : null;
                StartSentinel::run($this->server, true, null, $customImage);
            } else {
                $this->isMetricsEnabled = false;
                $this->isSentinelDebugEnabled = false;
                StopSentinel::dispatch($this->server);
            }
            $this->submit();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function regenerateSentinelToken()
    {
        try {
            $this->authorize('manageSentinel', $this->server);
            $this->server->settings->generateSentinelToken();
            $this->dispatch('success', 'Token regenerated. Restarting Sentinel.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function instantSave()
    {
        try {
            $this->syncData(true);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function checkHetznerServerStatus(bool $manual = false)
    {
        try {
            if (! $this->server->hetzner_server_id || ! $this->server->cloudProviderToken) {
                $this->dispatch('error', 'This server is not associated with a Hetzner Cloud server or token.');

                return;
            }

            $hetznerService = new HetznerService($this->server->cloudProviderToken->token);
            $serverData = $hetznerService->getServer($this->server->hetzner_server_id);

            $this->hetznerServerStatus = $serverData['status'] ?? null;

            // Save status to database without triggering model events
            if ($this->server->hetzner_server_status !== $this->hetznerServerStatus) {
                $this->server->hetzner_server_status = $this->hetznerServerStatus;
                $this->server->update(['hetzner_server_status' => $this->hetznerServerStatus]);
            }
            if ($manual) {
                $this->dispatch('success', 'Server status refreshed: '.ucfirst($this->hetznerServerStatus ?? 'unknown'));
            }

            // If Hetzner server is off but Coolify thinks it's still reachable, update Coolify's state
            if ($this->hetznerServerStatus === 'off' && $this->server->settings->is_reachable) {
                ['uptime' => $uptime, 'error' => $error] = $this->server->validateConnection();
                if ($uptime) {
                    $this->dispatch('success', 'Server is reachable.');
                    $this->server->settings->is_reachable = $this->isReachable = true;
                    $this->server->settings->is_usable = $this->isUsable = true;
                    $this->server->settings->save();
                    ServerReachabilityChanged::dispatch($this->server);
                } else {
                    $this->dispatch('error', 'Server is not reachable.', 'Please validate your configuration and connection.<br><br>Check this <a target="_blank" class="underline" href="https://coolify.io/docs/knowledge-base/server/openssh">documentation</a> for further help. <br><br>Error: '.$error);

                    return;
                }
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function handleServerValidated($event = null)
    {
        // Check if event is for this server
        if ($event && isset($event['serverUuid']) && $event['serverUuid'] !== $this->server->uuid) {
            return;
        }

        // Refresh server data
        $this->server->refresh();
        $this->syncData();

        // Update validation state
        $this->isValidating = $this->server->is_validating ?? false;

        // Reload Hetzner tokens in case the linking section should now be shown
        $this->loadHetznerTokens();
        $this->loadProviderControlBinding();

        $this->dispatch('refreshServerShow');
        $this->dispatch('refreshServer');
    }

    public function startHetznerServer()
    {
        try {
            if (! $this->server->hetzner_server_id || ! $this->server->cloudProviderToken) {
                $this->dispatch('error', 'This server is not associated with a Hetzner Cloud server or token.');

                return;
            }

            $hetznerService = new HetznerService($this->server->cloudProviderToken->token);
            $hetznerService->powerOnServer($this->server->hetzner_server_id);

            $this->hetznerServerStatus = 'starting';
            $this->server->update(['hetzner_server_status' => 'starting']);
            $this->hetznerServerManuallyStarted = true; // Set flag to trigger auto-validation when running
            $this->dispatch('success', 'Hetzner server is starting...');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function refreshServerMetadata(): void
    {
        try {
            $this->authorize('update', $this->server);
            $result = $this->server->gatherServerMetadata();
            if ($result) {
                $this->server->refresh();
                $this->dispatch('success', 'Server details refreshed.');
            } else {
                $this->dispatch('error', 'Could not fetch server details. Is the server reachable?');
            }
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function submit()
    {
        try {
            $this->syncData(true);
            $this->dispatch('success', 'Server settings updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function loadHetznerTokens(): void
    {
        $this->availableHetznerTokens = CloudProviderToken::ownedByCurrentTeam()
            ->where('provider', 'hetzner')
            ->get();
    }

    public function loadProviderControlBinding(): void
    {
        $providerKeys = $this->providerControlProviderKeys();

        $this->availableProviderControlTokens = CloudProviderToken::ownedByCurrentTeam()
            ->whereIn('provider', $providerKeys)
            ->orderBy('name')
            ->get();

        $this->selectedProviderControlTokenId = $this->providerControlTokenIdFromMetadata();
        $this->providerControlServerId = $this->providerControlServerIdFromMetadata();
        $this->providerControlStatus = data_get($this->server->server_metadata, 'provider_control.last_inspect');
    }

    public function saveProviderControlBinding(): void
    {
        try {
            $this->authorize('update', $this->server);
            $this->resetProviderControlFeedback();
            $this->validateProviderControlBinding();

            $token = $this->providerControlToken();
            $providerServerId = trim((string) $this->providerControlServerId);
            $metadata = $this->server->server_metadata ?? [];

            data_set($metadata, 'provider_server_id', $providerServerId);
            data_set($metadata, 'provider_control.provider', $token->provider);
            data_set($metadata, 'provider_control.token_id', $token->id);
            data_set($metadata, 'provider_control.provider_server_id', $providerServerId);
            data_set($metadata, 'provider_control.bound_at', now()->toIso8601String());

            $this->server->update([
                'server_metadata' => $metadata,
            ]);
            $this->server->refresh();

            $this->providerControlMessage = 'Provider binding saved. Use Inspect to read the provider state without touching the server.';
            $this->dispatch('success', 'Provider control binding saved.');
        } catch (\Throwable $e) {
            $this->providerControlError = 'Provider binding could not be saved.';
            handleError($e, $this);
        }
    }

    public function inspectProviderServer(): void
    {
        try {
            $this->authorize('update', $this->server);
            $this->resetProviderControlFeedback(clearStatus: false);
            $this->validateProviderControlBinding();

            $token = $this->providerControlToken();
            $providerServerId = trim((string) $this->providerControlServerId);
            $adapter = app(ProviderServerActionAdapterFactory::class)->make($token->provider, $token);
            $providerServer = $adapter->inspectServer($providerServerId, $token);

            $this->providerControlStatus = array_merge($providerServer->toArray(), [
                'inspected_at' => now()->toIso8601String(),
            ]);

            if ((int) data_get($this->server->server_metadata, 'provider_control.token_id') === (int) $token->id) {
                $metadata = $this->server->server_metadata ?? [];
                data_set($metadata, 'provider_server_id', $providerServerId);
                data_set($metadata, 'provider_control.provider', $token->provider);
                data_set($metadata, 'provider_control.token_id', $token->id);
                data_set($metadata, 'provider_control.provider_server_id', $providerServerId);
                data_set($metadata, 'provider_control.last_inspect', $this->providerControlStatus);
                $this->server->update(['server_metadata' => $metadata]);
                $this->server->refresh();
            }

            $this->providerControlMessage = 'Provider status refreshed with a read-only API call.';
            $this->dispatch('success', 'Provider status refreshed.');
        } catch (\Throwable $e) {
            $this->providerControlError = 'Provider inspect failed. Check the selected token, provider server ID, and provider availability.';
            report($e);
        }
    }

    public function planProviderControlAction(string $action): void
    {
        try {
            $this->authorize('update', $this->server);
            $this->resetProviderControlFeedback(clearStatus: false);
            $this->validateProviderControlBinding();

            $token = $this->providerControlToken();
            $providerServerId = trim((string) $this->providerControlServerId);
            $adapter = app(ProviderServerActionAdapterFactory::class)->make($token->provider, $token);
            $plan = $adapter->planAction($action, $providerServerId, $this->server)->toArray();

            if ($action === 'poweroff') {
                $result = app(ProtectionActionExecutor::class)->execute(new ProtectionAction(
                    type: ProtectionActionType::PROVIDER_POWEROFF_SERVER,
                    label: 'Power off provider server',
                    target: ['server_id' => $this->server->id],
                    payload: [
                        'provider' => $token->provider,
                        'provider_server_id' => $providerServerId,
                        'plan' => $plan,
                    ],
                    requiresApproval: true,
                    dryRun: true,
                    dangerous: true,
                ), ['team_id' => $this->server->team_id]);

                $plan['execution_guard'] = $result->toArray();
                $this->providerControlMessage = $result->message;
            } else {
                $plan['execution_guard'] = [
                    'status' => 'planned',
                    'blocked' => (bool) data_get($plan, 'dangerous', true),
                    'dry_run' => true,
                    'message' => 'This UI only plans provider actions. Approved execution is intentionally not wired here.',
                ];
                $this->providerControlMessage = 'Provider action planned only. No provider mutation API call was made.';
            }

            $this->providerControlActionPlan = $plan;
            $this->dispatch('info', 'Provider action planned. No provider mutation API call was made.');
        } catch (\Throwable $e) {
            $this->providerControlError = 'Provider action could not be planned.';
            handleError($e, $this);
        }
    }

    public function searchHetznerServer(): void
    {
        $this->hetznerSearchError = null;
        $this->hetznerNoMatchFound = false;
        $this->matchedHetznerServer = null;

        if (! $this->selectedHetznerTokenId) {
            $this->hetznerSearchError = 'Please select a Hetzner token.';

            return;
        }

        try {
            $this->authorize('update', $this->server);

            $token = $this->availableHetznerTokens->firstWhere('id', $this->selectedHetznerTokenId);
            if (! $token) {
                $this->hetznerSearchError = 'Invalid token selected.';

                return;
            }

            $hetznerService = new HetznerService($token->token);
            $matched = $hetznerService->findServerByIp($this->server->ip);

            if ($matched) {
                $this->matchedHetznerServer = $matched;
            } else {
                $this->hetznerNoMatchFound = true;
            }
        } catch (\Throwable $e) {
            $this->hetznerSearchError = 'Failed to search Hetzner servers: '.$e->getMessage();
        }
    }

    public function searchHetznerServerById(): void
    {
        $this->hetznerSearchError = null;
        $this->hetznerNoMatchFound = false;
        $this->matchedHetznerServer = null;

        if (! $this->selectedHetznerTokenId) {
            $this->hetznerSearchError = 'Please select a Hetzner token first.';

            return;
        }

        if (! $this->manualHetznerServerId) {
            $this->hetznerSearchError = 'Please enter a Hetzner Server ID.';

            return;
        }

        try {
            $this->authorize('update', $this->server);

            $token = $this->availableHetznerTokens->firstWhere('id', $this->selectedHetznerTokenId);
            if (! $token) {
                $this->hetznerSearchError = 'Invalid token selected.';

                return;
            }

            $hetznerService = new HetznerService($token->token);
            $serverData = $hetznerService->getServer((int) $this->manualHetznerServerId);

            if (! empty($serverData)) {
                $this->matchedHetznerServer = $serverData;
            } else {
                $this->hetznerNoMatchFound = true;
            }
        } catch (\Throwable $e) {
            $this->hetznerSearchError = 'Failed to fetch Hetzner server: '.$e->getMessage();
        }
    }

    public function linkToHetzner()
    {
        if (! $this->matchedHetznerServer) {
            $this->dispatch('error', 'No Hetzner server selected.');

            return;
        }

        try {
            $this->authorize('update', $this->server);

            $token = $this->availableHetznerTokens->firstWhere('id', $this->selectedHetznerTokenId);
            if (! $token) {
                $this->dispatch('error', 'Invalid token selected.');

                return;
            }

            // Verify the server exists and is accessible with the token
            $hetznerService = new HetznerService($token->token);
            $serverData = $hetznerService->getServer($this->matchedHetznerServer['id']);

            if (empty($serverData)) {
                $this->dispatch('error', 'Could not find Hetzner server with ID: '.$this->matchedHetznerServer['id']);

                return;
            }

            // Update the server with Hetzner details
            $this->server->update([
                'cloud_provider_token_id' => $this->selectedHetznerTokenId,
                'hetzner_server_id' => $this->matchedHetznerServer['id'],
                'hetzner_server_status' => $serverData['status'] ?? null,
            ]);

            $this->hetznerServerStatus = $serverData['status'] ?? null;

            // Clear the linking state
            $this->matchedHetznerServer = null;
            $this->selectedHetznerTokenId = null;
            $this->manualHetznerServerId = null;
            $this->hetznerNoMatchFound = false;
            $this->hetznerSearchError = null;

            $this->dispatch('success', 'Server successfully linked to Hetzner Cloud!');
            $this->dispatch('refreshServerShow');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.server.show');
    }

    private function providerControlProviderKeys(): array
    {
        return app(ProviderServerActionAdapterFactory::class)->supportedProviderKeys();
    }

    private function providerControlServerIdFromMetadata(): ?string
    {
        $metadata = $this->server->server_metadata ?? [];
        $providerServerId = collect([
            data_get($metadata, 'provider_control.provider_server_id'),
            data_get($metadata, 'provider_server_id'),
            data_get($metadata, 'selectel_vds_ctid'),
            data_get($metadata, 'hostinger_vps_id'),
        ])->first(fn (mixed $candidate): bool => filled($candidate));

        return filled($providerServerId) ? (string) $providerServerId : null;
    }

    private function providerControlTokenIdFromMetadata(): ?int
    {
        $tokenId = data_get($this->server->server_metadata, 'provider_control.token_id');
        if (filled($tokenId)) {
            return (int) $tokenId;
        }

        $boundToken = $this->server->cloudProviderToken;
        if ($boundToken && in_array($boundToken->provider, $this->providerControlProviderKeys(), true)) {
            return $boundToken->id;
        }

        return null;
    }

    private function validateProviderControlBinding(): void
    {
        $this->validate([
            'selectedProviderControlTokenId' => 'required|integer',
            'providerControlServerId' => 'required|string|max:255',
        ]);

        if (! $this->providerControlToken()) {
            throw new \Exception('Select a supported provider token.');
        }
    }

    private function providerControlToken(): ?CloudProviderToken
    {
        if (! $this->selectedProviderControlTokenId) {
            return null;
        }

        return CloudProviderToken::ownedByCurrentTeam()
            ->whereIn('provider', $this->providerControlProviderKeys())
            ->whereKey($this->selectedProviderControlTokenId)
            ->first();
    }

    private function resetProviderControlFeedback(bool $clearStatus = true): void
    {
        if ($clearStatus) {
            $this->providerControlStatus = null;
        }

        $this->providerControlActionPlan = null;
        $this->providerControlMessage = null;
        $this->providerControlError = null;
    }
}
