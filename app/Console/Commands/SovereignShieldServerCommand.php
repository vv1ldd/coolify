<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\SimpleL1Client;
use Illuminate\Console\Command;

class SovereignShieldServerCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sovereign:shield {server_id_or_uuid? : The ID or UUID of the target server to shield. Defaults to localhost}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Harden a server node by configuring a firewall (UFW), enabling Fail2Ban, securing SSH, and applying sysctl protections';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $selector = $this->argument('server_id_or_uuid') ?? 0;

        $server = Server::where('id', $selector)
            ->orWhere('uuid', $selector)
            ->first();

        if (! $server) {
            $this->error("❌ Server matching key [{$selector}] not found!");

            return 1;
        }

        $this->info("🛡️ Shielding Server: [{$server->name}] ({$server->ip})...");

        // 1. Prepare Shielding Bash Commands
        $sshPort = $server->port ?? 22;
        $commands = collect([
            "echo '==================================================='",
            "echo '⚡ LAUNCHING SOVEREIGN NODE SHIELDING & HARDENING'",
            "echo '==================================================='",

            // A. Update Package Index & Install security essentials
            "echo '📦 Installing security essentials (UFW, Fail2Ban, unattended-upgrades)...'",
            'export DEBIAN_FRONTEND=noninteractive',
            'apt-get update -y > /dev/null',
            'apt-get install -y ufw fail2ban unattended-upgrades > /dev/null',

            // B. Apply Sysctl Security Overrides (SYN Flood, Spoofing, Redirects)
            "echo '⚙️ Configuring kernel parameters in sysctl...'",
            "cat << 'EOF' > /etc/sysctl.d/99-sovereign-security.conf
# Sovereign Secure Server Hardening Configuration
net.ipv4.tcp_syncookies = 1
net.ipv4.conf.all.rp_filter = 1
net.ipv4.conf.default.rp_filter = 1
net.ipv4.conf.all.accept_source_route = 0
net.ipv4.conf.all.accept_redirects = 0
net.ipv4.conf.all.secure_redirects = 0
net.ipv6.conf.all.disable_ipv6 = 0
fs.protected_hardlinks = 1
fs.protected_symlinks = 1
EOF",
            'sysctl -p /etc/sysctl.d/99-sovereign-security.conf > /dev/null || true',

            // C. Configure Universal Firewall (UFW)
            "echo '🔥 Locking down ingress firewall rules (UFW)...'",
            'ufw --force reset > /dev/null',
            'ufw default deny incoming > /dev/null',
            'ufw default allow outgoing > /dev/null',
            "ufw allow {$sshPort}/tcp comment 'Sovereign Secure SSH' > /dev/null",
            "ufw allow 80/tcp comment 'HTTP traffic' > /dev/null",
            "ufw allow 443/tcp comment 'HTTPS traffic' > /dev/null",
            'ufw --force enable > /dev/null',

            // D. Configure & Start Fail2Ban for SSH brute force protection
            "echo '🔒 Shielding SSH logins with Fail2Ban...'",
            "cat << 'EOF' > /etc/fail2ban/jail.d/sovereign-ssh.local
[sshd]
enabled = true
port = {$sshPort}
filter = sshd
logpath = /var/log/auth.log
maxretry = 3
findtime = 600
bantime = 3600
EOF",
            'systemctl restart fail2ban > /dev/null || true',
            'systemctl enable fail2ban > /dev/null || true',

            // E. Enable Automatic unattended security upgrades
            "echo '🔄 Activating automated security patches...'",
            'systemctl enable unattended-upgrades > /dev/null || true',
            'systemctl start unattended-upgrades > /dev/null || true',

            // F. Set up Docker Daemon Log rotation to prevent disk exhaust attacks
            "echo '🐳 Restricting Docker container logs to 10MB limits...'",
            'mkdir -p /etc/docker',
            'if [ ! -f /etc/docker/daemon.json ]; then',
            "  echo '{\"log-driver\": \"json-file\", \"log-opts\": {\"max-size\": \"10m\", \"max-file\": \"3\"}}' > /etc/docker/daemon.json",
            'else',
            "  cat /etc/docker/daemon.json | jq '. + {\"log-driver\": \"json-file\", \"log-opts\": {\"max-size\": \"10m\", \"max-file\": \"3\"}}' > /etc/docker/daemon.json.tmp && mv /etc/docker/daemon.json.tmp /etc/docker/daemon.json",
            'fi',
            'systemctl reload docker > /dev/null || systemctl restart docker > /dev/null || true',

            "echo '==================================================='",
            "echo '🏆 SERVER NODE SUCCESSFULLY SHIELDED & HARDENED!'",
            "echo '==================================================='",
        ]);

        // 2. Dispatch execution process to the server
        try {
            $this->info('🚀 Dispatching secure shield payload to server...');

            // Using Coolify's built-in remote execution process
            $processOutput = instant_remote_process($commands->toArray(), $server, false);

            $this->line($processOutput);

            // 3. Anchor the Shield transaction onto L1 Blockchain Ledger
            $l1 = app(SimpleL1Client::class);
            $txHash = $l1->recordTransaction('SERVER_SHIELDED', [
                'server_id' => $server->id,
                'server_name' => $server->name,
                'server_ip' => $server->ip,
                'ssh_port' => $sshPort,
                'timestamp' => now()->toIso8601String(),
            ]);

            $this->info('🏆 Sovereign Node Shielding execution complete!');
            $this->line("⛓️ L1 Ledger Security Audit anchored: <fg=green>{$txHash}</fg=green>");

            return 0;

        } catch (\Throwable $e) {
            $this->error('❌ Server shielding failed: '.$e->getMessage());

            return 1;
        }
    }
}
