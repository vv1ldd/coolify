<?php

namespace App\Livewire\Agency;

use App\Livewire\GlobalSearch;
use App\Models\AgencyClient;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Clients extends Component
{
    public ?int $editingId = null;

    public array $form = [
        'name' => '',
        'contact_name' => '',
        'contact_email' => '',
        'country' => '',
        'timezone' => '',
        'status' => AgencyClient::STATUS_ACTIVE,
    ];

    public function render()
    {
        return view('livewire.agency.clients', [
            'clients' => AgencyClient::ownedByCurrentTeam()->withCount('engagements')->get(),
            'statuses' => [AgencyClient::STATUS_ACTIVE, AgencyClient::STATUS_ARCHIVED],
        ]);
    }

    public function save(): void
    {
        $teamId = currentTeam()->id;
        $data = $this->validate([
            'form.name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('agency_clients', 'name')
                    ->where('team_id', $teamId)
                    ->ignore($this->editingId),
            ],
            'form.contact_name' => ['nullable', 'string', 'max:255'],
            'form.contact_email' => ['nullable', 'email', 'max:255'],
            'form.country' => ['nullable', 'string', 'max:255'],
            'form.timezone' => ['nullable', 'string', 'max:255'],
            'form.status' => ['required', Rule::in([AgencyClient::STATUS_ACTIVE, AgencyClient::STATUS_ARCHIVED])],
        ])['form'];

        AgencyClient::query()->updateOrCreate(
            ['id' => $this->editingId, 'team_id' => $teamId],
            array_merge($data, ['team_id' => $teamId]),
        );

        GlobalSearch::clearTeamCache($teamId);
        $this->resetForm();
        $this->dispatch('success', 'Agency client saved.');
    }

    public function edit(string $uuid): void
    {
        $client = AgencyClient::ownedByCurrentTeam()->where('uuid', $uuid)->firstOrFail();
        $this->editingId = $client->id;
        $this->form = [
            'name' => $client->name,
            'contact_name' => $client->contact_name,
            'contact_email' => $client->contact_email,
            'country' => $client->country,
            'timezone' => $client->timezone,
            'status' => $client->status,
        ];
    }

    public function delete(string $uuid): void
    {
        AgencyClient::ownedByCurrentTeam()->where('uuid', $uuid)->firstOrFail()->delete();
        GlobalSearch::clearTeamCache(currentTeam()->id);
        $this->resetForm();
        $this->dispatch('success', 'Agency client deleted.');
    }

    public function resetForm(): void
    {
        $this->editingId = null;
        $this->resetValidation();
        $this->form = [
            'name' => '',
            'contact_name' => '',
            'contact_email' => '',
            'country' => '',
            'timezone' => '',
            'status' => AgencyClient::STATUS_ACTIVE,
        ];
    }
}
