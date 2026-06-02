<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('application configuration renders when destination server is missing', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    InstanceSettings::unguarded(fn () => InstanceSettings::create(['id' => 0]));

    $this->actingAs($user);
    session(['currentTeam' => $team]);

    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => null,
        'destination_type' => null,
        'build_pack' => 'nixpacks',
    ]);

    $this->get(route('project.application.configuration', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'application_uuid' => $application->uuid,
    ]))->assertOk();
});
