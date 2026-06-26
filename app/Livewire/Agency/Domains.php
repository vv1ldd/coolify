<?php

namespace App\Livewire\Agency;

use App\Livewire\GlobalSearch;
use App\Models\AgencyDomainAsset;
use App\Models\AgencyEngagement;
use App\Models\DnsZone;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Domains extends Component
{
    public ?int $editingId = null;

    public array $form = [
        'engagement_uuid' => '',
        'dns_zone_uuid' => '',
        'domain' => '',
        'registrar' => '',
        'expires_at' => '',
        'status' => AgencyDomainAsset::STATUS_ACTIVE,
        'ownership_notes' => '',
    ];

    public function render()
    {
        return view('livewire.agency.domains', [
            'domains' => AgencyDomainAsset::ownedByCurrentTeam()
                ->with(['engagement.client', 'dnsZone'])
                ->get(),
            'engagements' => AgencyEngagement::ownedByCurrentTeam()->with('client')->get(),
            'zones' => DnsZone::query()->where('team_id', currentTeam()->id)->orderBy('name')->get(),
            'statuses' => [
                AgencyDomainAsset::STATUS_ACTIVE,
                AgencyDomainAsset::STATUS_EXPIRING,
                AgencyDomainAsset::STATUS_EXPIRED,
                AgencyDomainAsset::STATUS_RELEASED,
            ],
        ]);
    }

    public function save(): void
    {
        $teamId = currentTeam()->id;
        $data = $this->validate([
            'form.engagement_uuid' => ['nullable', 'string'],
            'form.dns_zone_uuid' => ['nullable', 'string'],
            'form.domain' => [
                'required',
                'string',
                'max:255',
                Rule::unique('agency_domain_assets', 'domain')
                    ->where('team_id', $teamId)
                    ->ignore($this->editingId),
            ],
            'form.registrar' => ['nullable', 'string', 'max:255'],
            'form.expires_at' => ['nullable', 'date'],
            'form.status' => ['required', Rule::in([
                AgencyDomainAsset::STATUS_ACTIVE,
                AgencyDomainAsset::STATUS_EXPIRING,
                AgencyDomainAsset::STATUS_EXPIRED,
                AgencyDomainAsset::STATUS_RELEASED,
            ])],
            'form.ownership_notes' => ['nullable', 'string'],
        ])['form'];

        $engagement = filled($data['engagement_uuid'])
            ? AgencyEngagement::ownedByCurrentTeam()->where('uuid', $data['engagement_uuid'])->firstOrFail()
            : null;
        $zone = filled($data['dns_zone_uuid'])
            ? DnsZone::query()->where('team_id', $teamId)->where('uuid', $data['dns_zone_uuid'])->firstOrFail()
            : null;

        AgencyDomainAsset::query()->updateOrCreate(
            ['id' => $this->editingId, 'team_id' => $teamId],
            [
                'team_id' => $teamId,
                'agency_engagement_id' => $engagement?->id,
                'dns_zone_id' => $zone?->id,
                'domain' => strtolower(trim($data['domain'])),
                'registrar' => $data['registrar'] ?: null,
                'expires_at' => filled($data['expires_at']) ? $data['expires_at'] : null,
                'status' => $data['status'],
                'ownership_notes' => $data['ownership_notes'] ?: null,
            ],
        );

        GlobalSearch::clearTeamCache($teamId);
        $this->resetForm();
        $this->dispatch('success', 'Agency domain asset saved.');
    }

    public function edit(string $uuid): void
    {
        $domain = AgencyDomainAsset::ownedByCurrentTeam()
            ->with(['engagement', 'dnsZone'])
            ->where('uuid', $uuid)
            ->firstOrFail();

        $this->editingId = $domain->id;
        $this->form = [
            'engagement_uuid' => $domain->engagement?->uuid ?: '',
            'dns_zone_uuid' => $domain->dnsZone?->uuid ?: '',
            'domain' => $domain->domain,
            'registrar' => $domain->registrar,
            'expires_at' => $domain->expires_at?->format('Y-m-d') ?: '',
            'status' => $domain->status,
            'ownership_notes' => $domain->ownership_notes,
        ];
    }

    public function delete(string $uuid): void
    {
        AgencyDomainAsset::ownedByCurrentTeam()->where('uuid', $uuid)->firstOrFail()->delete();
        GlobalSearch::clearTeamCache(currentTeam()->id);
        $this->resetForm();
        $this->dispatch('success', 'Agency domain asset deleted.');
    }

    public function resetForm(): void
    {
        $this->editingId = null;
        $this->resetValidation();
        $this->form = [
            'engagement_uuid' => '',
            'dns_zone_uuid' => '',
            'domain' => '',
            'registrar' => '',
            'expires_at' => '',
            'status' => AgencyDomainAsset::STATUS_ACTIVE,
            'ownership_notes' => '',
        ];
    }
}
