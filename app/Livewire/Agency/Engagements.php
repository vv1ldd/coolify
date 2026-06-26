<?php

namespace App\Livewire\Agency;

use App\Livewire\GlobalSearch;
use App\Models\AgencyClient;
use App\Models\AgencyEngagement;
use App\Models\Project;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Engagements extends Component
{
    public ?int $editingId = null;

    public array $form = [
        'client_uuid' => '',
        'project_uuid' => '',
        'name' => '',
        'status' => AgencyEngagement::STATUS_PENDING,
        'priority' => AgencyEngagement::PRIORITY_NORMAL,
        'starts_at' => '',
        'due_at' => '',
        'notes' => '',
    ];

    public function render()
    {
        return view('livewire.agency.engagements', [
            'engagements' => AgencyEngagement::ownedByCurrentTeam()
                ->with(['client', 'project'])
                ->get(),
            'clients' => AgencyClient::ownedByCurrentTeam()->get(),
            'projects' => Project::ownedByCurrentTeam()->get(),
            'statuses' => AgencyEngagement::statuses(),
            'priorities' => [
                AgencyEngagement::PRIORITY_LOW,
                AgencyEngagement::PRIORITY_NORMAL,
                AgencyEngagement::PRIORITY_HIGH,
            ],
        ]);
    }

    public function save(): void
    {
        $teamId = currentTeam()->id;
        $data = $this->validate([
            'form.client_uuid' => ['required', 'string'],
            'form.project_uuid' => ['nullable', 'string'],
            'form.name' => ['required', 'string', 'max:255'],
            'form.status' => ['required', Rule::in(AgencyEngagement::statuses())],
            'form.priority' => ['required', Rule::in([
                AgencyEngagement::PRIORITY_LOW,
                AgencyEngagement::PRIORITY_NORMAL,
                AgencyEngagement::PRIORITY_HIGH,
            ])],
            'form.starts_at' => ['nullable', 'date'],
            'form.due_at' => ['nullable', 'date'],
            'form.notes' => ['nullable', 'string'],
        ])['form'];

        $client = AgencyClient::ownedByCurrentTeam()->where('uuid', $data['client_uuid'])->firstOrFail();
        $project = filled($data['project_uuid'])
            ? Project::ownedByCurrentTeam()->where('uuid', $data['project_uuid'])->firstOrFail()
            : null;

        AgencyEngagement::query()->updateOrCreate(
            ['id' => $this->editingId, 'team_id' => $teamId],
            [
                'team_id' => $teamId,
                'agency_client_id' => $client->id,
                'project_id' => $project?->id,
                'name' => $data['name'],
                'status' => $data['status'],
                'priority' => $data['priority'],
                'starts_at' => filled($data['starts_at']) ? $data['starts_at'] : null,
                'due_at' => filled($data['due_at']) ? $data['due_at'] : null,
                'notes' => $data['notes'] ?: null,
            ],
        );

        GlobalSearch::clearTeamCache($teamId);
        $this->resetForm();
        $this->dispatch('success', 'Agency engagement saved.');
    }

    public function edit(string $uuid): void
    {
        $engagement = AgencyEngagement::ownedByCurrentTeam()
            ->with(['client', 'project'])
            ->where('uuid', $uuid)
            ->firstOrFail();

        $this->editingId = $engagement->id;
        $this->form = [
            'client_uuid' => $engagement->client?->uuid ?: '',
            'project_uuid' => $engagement->project?->uuid ?: '',
            'name' => $engagement->name,
            'status' => $engagement->status,
            'priority' => $engagement->priority,
            'starts_at' => $engagement->starts_at?->format('Y-m-d') ?: '',
            'due_at' => $engagement->due_at?->format('Y-m-d') ?: '',
            'notes' => $engagement->notes,
        ];
    }

    public function delete(string $uuid): void
    {
        AgencyEngagement::ownedByCurrentTeam()->where('uuid', $uuid)->firstOrFail()->delete();
        GlobalSearch::clearTeamCache(currentTeam()->id);
        $this->resetForm();
        $this->dispatch('success', 'Agency engagement deleted.');
    }

    public function resetForm(): void
    {
        $this->editingId = null;
        $this->resetValidation();
        $this->form = [
            'client_uuid' => '',
            'project_uuid' => '',
            'name' => '',
            'status' => AgencyEngagement::STATUS_PENDING,
            'priority' => AgencyEngagement::PRIORITY_NORMAL,
            'starts_at' => '',
            'due_at' => '',
            'notes' => '',
        ];
    }
}
