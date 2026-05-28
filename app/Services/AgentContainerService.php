<?php

namespace App\Services;

use App\Models\Server;
use App\Support\ValidationPatterns;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class AgentContainerService
{
    public const MAX_LOG_LINES = 5000;

    public const MAX_EXEC_TIMEOUT_SECONDS = 60;

    public function list(Server $server): Collection
    {
        $output = instant_remote_process([
            "docker ps -a --format '{{json .}}'",
        ], $server, false);

        return format_docker_command_output_to_json($output ?? '')
            ->map(fn (array $container) => $this->normalizeContainer($container))
            ->values();
    }

    public function logs(Server $server, string $container, int $lines = 200, bool $timestamps = true): string
    {
        $this->ensureValidContainerName($container);

        $lines = min(max($lines, 1), self::MAX_LOG_LINES);
        $containerName = escapeshellarg($container);
        $timestampFlag = $timestamps ? '-t ' : '';

        $command = $server->isSwarm()
            ? "docker service logs -n {$lines} {$timestampFlag}{$containerName}"
            : "docker logs -n {$lines} {$timestampFlag}{$containerName}";

        return removeAnsiColors(instant_remote_process([$command], $server, false) ?? '');
    }

    public function exec(Server $server, string $container, string $command, int $timeout = 15): string
    {
        $this->ensureValidContainerName($container);

        if (! preg_match(ValidationPatterns::SHELL_SAFE_COMMAND_PATTERN, $command)) {
            throw new InvalidArgumentException('Command contains shell-unsafe characters.');
        }

        $timeout = min(max($timeout, 1), self::MAX_EXEC_TIMEOUT_SECONDS);
        $containerName = escapeshellarg($container);
        $innerCommand = escapeshellarg($command);

        return removeAnsiColors(instant_remote_process([
            "docker exec {$containerName} sh -lc {$innerCommand}",
        ], $server, false, timeout: $timeout) ?? '');
    }

    public function ensureValidContainerName(string $container): void
    {
        if (! ValidationPatterns::isValidContainerName($container)) {
            throw new InvalidArgumentException('Invalid container name.');
        }
    }

    private function normalizeContainer(array $container): array
    {
        $labels = data_get($container, 'Labels', '');

        return [
            'id' => data_get($container, 'ID'),
            'name' => data_get($container, 'Names'),
            'image' => data_get($container, 'Image'),
            'state' => data_get($container, 'State'),
            'status' => data_get($container, 'Status'),
            'ports' => data_get($container, 'Ports'),
            'networks' => data_get($container, 'Networks'),
            'labels' => $labels,
            'managed' => str($labels)->contains('coolify.managed=true'),
            'resource_type' => $this->labelValue($labels, 'coolify.type'),
            'application_id' => $this->labelValue($labels, 'coolify.applicationId'),
            'service_id' => $this->labelValue($labels, 'coolify.serviceId'),
        ];
    }

    private function labelValue(string $labels, string $key): ?string
    {
        if (preg_match('/(?:^|,)'.preg_quote($key, '/').'=([^,]*)/', $labels, $matches) !== 1) {
            return null;
        }

        return $matches[1] !== '' ? $matches[1] : null;
    }
}
