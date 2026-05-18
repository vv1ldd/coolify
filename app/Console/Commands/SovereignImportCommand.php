<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Console\Command;
use Visus\Cuid2\Cuid2;

class SovereignImportCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sovereign:import {file_path : Path to the exported JSON file} {--server= : Destination server ID to bind imported applications to}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import a Coolify project and deploy its services onto a sovereign target host';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $filePath = $this->argument('file_path');

        if (! file_exists($filePath)) {
            $this->error("❌ Import file [{$filePath}] not found!");

            return 1;
        }

        $json = file_get_contents($filePath);
        $data = json_decode($json, true);

        if (! $data || ! isset($data['project'])) {
            $this->error('❌ Invalid JSON migration packet format!');

            return 1;
        }

        // 1. Resolve Target Team
        $team = Team::first();
        if (! $team) {
            $this->error('❌ No target teams found in Coolify registry!');

            return 1;
        }

        // 2. Resolve Target Server for deployments
        $serverId = $this->option('server');
        $server = $serverId ? Server::find($serverId) : Server::first();

        if (! $server) {
            $this->error('❌ Destination server not found! Please connect a server or specify one using --server=[id].');

            return 1;
        }

        $projectData = $data['project'];
        $this->info("🚀 Importing Project: [{$projectData['name']}] into Team: [{$team->name}]...");

        // 3. Create or resolve Project
        $project = Project::updateOrCreate(
            ['name' => $projectData['name'], 'team_id' => $team->id],
            [
                'description' => $projectData['description'] ?? 'Imported via Sovereign Migrator',
                'uuid' => $projectData['uuid'] ?? (string) new Cuid2,
            ]
        );

        // 4. Reconstruct Environments and Applications
        foreach ($projectData['environments'] ?? [] as $envData) {
            $this->line("👉 Restoring Environment: [{$envData['name']}]...");

            $environment = Environment::updateOrCreate(
                ['name' => $envData['name'], 'project_id' => $project->id],
                ['uuid' => $envData['uuid'] ?? (string) new Cuid2]
            );

            foreach ($envData['applications'] ?? [] as $appData) {
                $this->line("  📦 Restoring Application: [{$appData['name']}]...");

                $destination = $server->destinations()->first();
                if (! $destination) {
                    $this->warn("  ⚠️ No network destination found on server [{$server->name}]. Skipping app [{$appData['name']}].");

                    continue;
                }

                // Resolve Nixpacks or custom buildpack setting
                $app = Application::updateOrCreate(
                    ['name' => $appData['name'], 'environment_id' => $environment->id],
                    [
                        'uuid' => $appData['uuid'] ?? (string) new Cuid2,
                        'fqdn' => $appData['fqdn'] ?? null,
                        'git_repository' => $appData['git_repository'],
                        'git_branch' => $appData['git_branch'],
                        'build_pack' => $appData['build_pack'] ?? 'nixpacks',
                        'ports_mappings' => $appData['ports_mappings'] ?? null,
                        'ports_exposes' => $appData['ports_exposes'] ?? null,
                        'install_command' => $appData['install_command'] ?? null,
                        'build_command' => $appData['build_command'] ?? null,
                        'start_command' => $appData['start_command'] ?? null,
                        'destination_type' => get_class($destination),
                        'destination_id' => $destination->id,
                        'status' => 'stopped',
                    ]
                );

                // Reconstruct Environment Variables
                foreach ($appData['environment_variables'] ?? [] as $var) {
                    if (class_exists(EnvironmentVariable::class)) {
                        EnvironmentVariable::updateOrCreate(
                            [
                                'key' => $var['key'],
                                'application_id' => $app->id,
                            ],
                            [
                                'value' => $var['value'],
                                'is_build_time' => $var['is_build_time'] ?? false,
                                'is_literal' => $var['is_literal'] ?? false,
                            ]
                        );
                    }
                }

                $this->info("  ✅ Application [{$app->name}] restored perfectly!");
            }
        }

        $this->info('🏆 Sovereign Project Migration successfully executed!');

        return 0;
    }
}
