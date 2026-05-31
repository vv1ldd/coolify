<?php

namespace App\Livewire\Project\Application;

use App\Actions\Application\StopApplication;
use App\Actions\Docker\GetContainersStatus;
use App\Models\Application;
use App\Services\InfraLedgerService;
use App\Services\PolicyEngine;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class Heading extends Component
{
    use AuthorizesRequests;

    public Application $application;

    public ?string $lastDeploymentInfo = null;

    public ?string $lastDeploymentLink = null;

    public array $parameters;

    protected string $deploymentUuid;

    public bool $docker_cleanup = true;

    public function getListeners()
    {
        $teamId = auth()->user()->currentTeam()->id;

        return [
            "echo-private:team.{$teamId},ServiceStatusChanged" => 'checkStatus',
            "echo-private:team.{$teamId},ServiceChecked" => '$refresh',
            'compose_loaded' => '$refresh',
            'update_links' => '$refresh',
        ];
    }

    public function mount()
    {
        $this->parameters = [
            'project_uuid' => $this->application->project()->uuid,
            'environment_uuid' => $this->application->environment->uuid,
            'application_uuid' => $this->application->uuid,
        ];
        $lastDeployment = $this->application->get_last_successful_deployment();
        $this->lastDeploymentInfo = data_get_str($lastDeployment, 'commit')->limit(7).' '.data_get($lastDeployment, 'commit_message');
        $this->lastDeploymentLink = $this->application->gitCommitLink(data_get($lastDeployment, 'commit'));
    }

    public function checkStatus()
    {
        if ($this->application->destination->server->isFunctional()) {
            GetContainersStatus::dispatch($this->application->destination->server);
        } else {
            $this->dispatch('error', 'Server is not functional.');
        }
    }

    public function manualCheckStatus()
    {
        $this->checkStatus();
    }

    public function force_deploy_without_cache()
    {
        $this->authorize('deploy', $this->application);

        $this->deploy(force_rebuild: true);
    }

    public function deploy(bool $force_rebuild = false, bool $mandateApproved = false)
    {
        $this->authorize('deploy', $this->application);

        // 🏛️ Sovereign operational approval interception for deploying production workloads
        if (! $mandateApproved && app(PolicyEngine::class)->requiresApproval('application.deploy')) {
            $intent = app(PolicyEngine::class)->stage(
                eventType: 'application.deploy',
                entity: $this->application,
                payload: [
                    'application_id' => $this->application->id,
                    'application_name' => $this->application->name,
                    'force_rebuild' => $force_rebuild,
                ],
                teamId: $this->application->team_id
            );

            $this->dispatch('open-sl1-intent-popup', url: route('auth.sl1.intent.redirect', [
                'intent' => $intent->id,
                'popup' => 1,
            ]));
            $this->dispatch('success', 'Deployment Intent staged in Pending Pool for cryptographic clearance.');

            return;
        }

        if ($this->application->build_pack === 'dockercompose' && is_null($this->application->docker_compose_raw)) {
            $this->dispatch('error', 'Failed to deploy', 'Please load a Compose file first.');

            return;
        }
        if ($this->application->destination->server->isSwarm() && str($this->application->docker_registry_image_name)->isEmpty()) {
            $this->dispatch('error', 'Failed to deploy.', 'To deploy to a Swarm cluster you must set a Docker image name first.');

            return;
        }
        if (data_get($this->application, 'settings.is_build_server_enabled') && str($this->application->docker_registry_image_name)->isEmpty()) {
            $this->dispatch('error', 'Failed to deploy.', 'To use a build server, you must first set a Docker image.<br>More information here: <a target="_blank" class="underline" href="https://coolify.io/docs/knowledge-base/server/build-server">documentation</a>');

            return;
        }
        if ($this->application->additional_servers->count() > 0 && str($this->application->docker_registry_image_name)->isEmpty()) {
            $this->dispatch('error', 'Failed to deploy.', 'Before deploying to multiple servers, you must first set a Docker image in the General tab.<br>More information here: <a target="_blank" class="underline" href="https://coolify.io/docs/knowledge-base/server/multiple-servers">documentation</a>');

            return;
        }
        $this->setDeploymentUuid();
        $result = queue_application_deployment(
            application: $this->application,
            deployment_uuid: $this->deploymentUuid,
            force_rebuild: $force_rebuild,
        );
        if ($result['status'] === 'queue_full') {
            $this->dispatch('error', 'Deployment queue full', $result['message']);

            return;
        }
        if ($result['status'] === 'skipped') {
            $this->dispatch('error', 'Deployment skipped', $result['message']);

            return;
        }

        // ⚓ Sovereign Ledger: Record Execution Intent
        app(InfraLedgerService::class)->record(
            eventType: 'application.deploy',
            entity: $this->application,
            payload: [
                'deployment_uuid' => (string) $this->deploymentUuid,
                'force_rebuild' => $force_rebuild,
                'server_uuid' => $this->application->destination?->server?->uuid,
                'build_pack' => $this->application->build_pack,
            ],
            inputState: [
                'status' => $this->application->status,
            ],
        );

        return $this->redirectRoute('project.application.deployment.show', [
            'project_uuid' => $this->parameters['project_uuid'],
            'application_uuid' => $this->parameters['application_uuid'],
            'deployment_uuid' => $this->deploymentUuid,
            'environment_uuid' => $this->parameters['environment_uuid'],
        ], navigate: false);
    }

    protected function setDeploymentUuid()
    {
        $this->deploymentUuid = new Cuid2;
        $this->parameters['deployment_uuid'] = $this->deploymentUuid;
    }

    public function stop(bool $mandateApproved = false)
    {
        $this->authorize('deploy', $this->application);

        // 🏛️ Sovereign operational approval interception for terminating container workloads
        if (! $mandateApproved && app(PolicyEngine::class)->requiresApproval('application.stop')) {
            $intent = app(PolicyEngine::class)->stage(
                eventType: 'application.stop',
                entity: $this->application,
                payload: [
                    'application_id' => $this->application->id,
                    'application_name' => $this->application->name,
                ],
                teamId: $this->application->team_id
            );

            $this->dispatch('open-sl1-intent-popup', url: route('auth.sl1.intent.redirect', [
                'intent' => $intent->id,
                'popup' => 1,
            ]));
            $this->dispatch('success', 'Termination Intent staged in Pending Pool for cryptographic clearance.');

            return;
        }

        // ⚓ Sovereign Ledger: Record Stop Intent
        app(InfraLedgerService::class)->record(
            eventType: 'application.stop',
            entity: $this->application,
            payload: [
                'docker_cleanup' => $this->docker_cleanup,
                'server_uuid' => $this->application->destination?->server?->uuid,
            ],
            inputState: [
                'status' => $this->application->status,
            ],
        );

        $this->dispatch('info', 'Gracefully stopping application.<br/>It could take a while depending on the application.');
        StopApplication::dispatch($this->application, false, $this->docker_cleanup);
    }

    public function restart()
    {
        $this->authorize('deploy', $this->application);

        if ($this->application->additional_servers->count() > 0 && str($this->application->docker_registry_image_name)->isEmpty()) {
            $this->dispatch('error', 'Failed to deploy', 'Before deploying to multiple servers, you must first set a Docker image in the General tab.<br>More information here: <a target="_blank" class="underline" href="https://coolify.io/docs/knowledge-base/server/multiple-servers">documentation</a>');

            return;
        }

        $this->setDeploymentUuid();
        $result = queue_application_deployment(
            application: $this->application,
            deployment_uuid: $this->deploymentUuid,
            restart_only: true,
        );
        if ($result['status'] === 'queue_full') {
            $this->dispatch('error', 'Deployment queue full', $result['message']);

            return;
        }
        if ($result['status'] === 'skipped') {
            $this->dispatch('success', 'Deployment skipped', $result['message']);

            return;
        }

        // ⚓ Sovereign Ledger: Record Restart Intent
        app(InfraLedgerService::class)->record(
            eventType: 'application.restart',
            entity: $this->application,
            payload: [
                'deployment_uuid' => (string) $this->deploymentUuid,
                'server_uuid' => $this->application->destination?->server?->uuid,
            ],
            inputState: [
                'status' => $this->application->status,
            ],
        );

        return $this->redirectRoute('project.application.deployment.show', [
            'project_uuid' => $this->parameters['project_uuid'],
            'application_uuid' => $this->parameters['application_uuid'],
            'deployment_uuid' => $this->deploymentUuid,
            'environment_uuid' => $this->parameters['environment_uuid'],
        ], navigate: false);
    }

    public function render()
    {
        return view('livewire.project.application.heading', [
            'checkboxes' => [
                ['id' => 'docker_cleanup', 'label' => __('resource.docker_cleanup')],
            ],
        ]);
    }
}
