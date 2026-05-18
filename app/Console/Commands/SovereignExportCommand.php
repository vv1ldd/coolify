<?php

namespace App\Console\Commands;

use App\Models\Project;
use Illuminate\Console\Command;

class SovereignExportCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sovereign:export {project_id_or_uuid : The ID or UUID of the project to export} {--out= : Path to save the exported JSON file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Export a Coolify project and its resources into a portable JSON migration format';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $selector = $this->argument('project_id_or_uuid');

        $project = Project::where('id', $selector)
            ->orWhere('uuid', $selector)
            ->with(['environments.applications', 'environments.postgresqls', 'environments.redis'])
            ->first();

        if (! $project) {
            $this->error("❌ Project matching key [{$selector}] not found!");

            return 1;
        }

        $this->info("✨ Serializing Project: [{$project->name}] ({$project->uuid})...");

        // 1. Pack environments with applications, databases, and variables
        $environmentsPayload = [];
        foreach ($project->environments as $env) {
            $applications = [];
            foreach ($env->applications as $app) {
                // Get application environment variables
                $envVars = [];
                if (method_exists($app, 'environment_variables')) {
                    $envVars = $app->environment_variables()->get()->map(fn ($v) => [
                        'key' => $v->key,
                        'value' => $v->value,
                        'is_build_time' => $v->is_build_time ?? false,
                        'is_literal' => $v->is_literal ?? false,
                    ])->toArray();
                }

                $applications[] = [
                    'name' => $app->name,
                    'uuid' => $app->uuid,
                    'fqdn' => $app->fqdn,
                    'git_repository' => $app->git_repository,
                    'git_branch' => $app->git_branch,
                    'build_pack' => $app->build_pack,
                    'ports_mappings' => $app->ports_mappings,
                    'ports_exposes' => $app->ports_exposes,
                    'install_command' => $app->install_command,
                    'build_command' => $app->build_command,
                    'start_command' => $app->start_command,
                    'environment_variables' => $envVars,
                ];
            }

            $environmentsPayload[] = [
                'name' => $env->name,
                'uuid' => $env->uuid,
                'applications' => $applications,
            ];
        }

        // 2. Build the final migration envelope
        $exportData = [
            'version' => '1.0.0',
            'exported_at' => now()->toIso8601String(),
            'project' => [
                'name' => $project->name,
                'uuid' => $project->uuid,
                'description' => $project->description,
                'environments' => $environmentsPayload,
            ],
        ];

        $jsonOutput = json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        // 3. Write output to file or console
        $outputPath = $this->option('out') ?: storage_path("app/sovereign_export_{$project->uuid}.json");

        file_put_contents($outputPath, $jsonOutput);

        $this->info('🏆 Export completed successfully!');
        $this->line("📂 File saved to: <fg=green>{$outputPath}</fg=green>");

        return 0;
    }
}
