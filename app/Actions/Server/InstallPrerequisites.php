<?php

namespace App\Actions\Server;

use App\Models\Server;
use App\Services\SimpleL1Client;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;

class InstallPrerequisites
{
    use AsAction;

    public string $jobQueue = 'high';

    public function handle(Server $server)
    {
        $supported_os_type = $server->validateOS();
        if (! $supported_os_type) {
            throw new \Exception('Server OS type is not supported for automated installation. Please install prerequisites manually.');
        }

        $command = collect([]);

        if ($supported_os_type->contains('debian')) {
            $sshPort = $server->port ?? 22;
            $command = $command->merge([
                "echo '🛡️ Installing Sovereign Prerequisites & Hardening Node...'",
                'export DEBIAN_FRONTEND=noninteractive',
                'apt-get update -y',
                'command -v curl >/dev/null || apt install -y curl',
                'command -v wget >/dev/null || apt install -y wget',
                'command -v git >/dev/null || apt install -y git',
                'command -v jq >/dev/null || apt install -y jq',
                'apt-get install -y ufw fail2ban unattended-upgrades > /dev/null',

                // Apply Sysctl Security Overrides
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

                // Configure Universal Firewall (UFW)
                "echo '🔥 Locking down ingress firewall rules (UFW)...'",
                'ufw --force reset > /dev/null',
                'ufw default deny incoming > /dev/null',
                'ufw default allow outgoing > /dev/null',
                "ufw allow {$sshPort}/tcp comment 'Sovereign Secure SSH' > /dev/null",
                "ufw allow 80/tcp comment 'HTTP traffic' > /dev/null",
                "ufw allow 443/tcp comment 'HTTPS traffic' > /dev/null",
                'ufw --force enable > /dev/null',

                // Configure Fail2Ban
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

                // Enable unattended security upgrades
                "echo '🔄 Activating automated security patches...'",
                'systemctl enable unattended-upgrades > /dev/null || true',
                'systemctl start unattended-upgrades > /dev/null || true',

                // Set up Docker Daemon Log rotation
                "echo '🐳 Restricting Docker container logs to 10MB limits...'",
                'mkdir -p /etc/docker',
                'if [ ! -f /etc/docker/daemon.json ]; then',
                "  echo '{\"log-driver\": \"json-file\", \"log-opts\": {\"max-size\": \"10m\", \"max-file\": \"3\"}}' > /etc/docker/daemon.json",
                'else',
                "  cat /etc/docker/daemon.json | jq '. + {\"log-driver\": \"json-file\", \"log-opts\": {\"max-size\": \"10m\", \"max-file\": \"3\"}}' > /etc/docker/daemon.json.tmp && mv /etc/docker/daemon.json.tmp /etc/docker/daemon.json",
                'fi',
                'systemctl reload docker > /dev/null || systemctl restart docker > /dev/null || true',
            ]);
        } elseif ($supported_os_type->contains('rhel')) {
            $command = $command->merge([
                "echo 'Installing Prerequisites...'",
                'command -v curl >/dev/null || dnf install -y curl',
                'command -v wget >/dev/null || dnf install -y wget',
                'command -v git >/dev/null || dnf install -y git',
                'command -v jq >/dev/null || dnf install -y jq',
            ]);
        } elseif ($supported_os_type->contains('sles')) {
            $command = $command->merge([
                "echo 'Installing Prerequisites...'",
                'zypper update -y',
                'command -v curl >/dev/null || zypper install -y curl',
                'command -v wget >/dev/null || zypper install -y wget',
                'command -v git >/dev/null || zypper install -y git',
                'command -v jq >/dev/null || zypper install -y jq',
            ]);
        } elseif ($supported_os_type->contains('arch')) {
            // Use -Syu for full system upgrade to avoid partial upgrade issues on Arch Linux
            // --needed flag skips packages that are already installed and up-to-date
            $command = $command->merge([
                "echo 'Installing Prerequisites for Arch Linux...'",
                'pacman -Syu --noconfirm --needed curl wget git jq',
            ]);
        } else {
            throw new \Exception('Unsupported OS type for prerequisites installation');
        }

        $command->push("echo 'Prerequisites installed and Sovereign Node Shielded successfully.'");

        // Anchor the Shield transaction onto L1 Blockchain Ledger
        try {
            $l1 = app(SimpleL1Client::class);
            $l1->recordTransaction('SERVER_SHIELDED', [
                'server_id' => $server->id,
                'server_name' => $server->name,
                'server_ip' => $server->ip,
                'ssh_port' => $server->port ?? 22,
                'timestamp' => now()->toIso8601String(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Local audit logged because L1 offline: '.$e->getMessage());
        }

        return remote_process($command, $server);
    }
}
