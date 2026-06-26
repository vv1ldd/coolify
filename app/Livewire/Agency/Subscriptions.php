<?php

namespace App\Livewire\Agency;

use App\Livewire\GlobalSearch;
use App\Models\AgencyEngagement;
use App\Models\AgencyToolSubscription;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Subscriptions extends Component
{
    public ?int $editingId = null;

    public array $form = [
        'engagement_uuid' => '',
        'vendor' => '',
        'tool_name' => '',
        'amount' => '0.00',
        'currency' => 'USD',
        'interval' => AgencyToolSubscription::INTERVAL_MONTHLY,
        'renews_at' => '',
        'status' => AgencyToolSubscription::STATUS_ACTIVE,
        'owner_notes' => '',
    ];

    public function render()
    {
        return view('livewire.agency.subscriptions', [
            'subscriptions' => AgencyToolSubscription::ownedByCurrentTeam()
                ->with('engagement.client')
                ->get(),
            'engagements' => AgencyEngagement::ownedByCurrentTeam()->with('client')->get(),
            'statuses' => [
                AgencyToolSubscription::STATUS_ACTIVE,
                AgencyToolSubscription::STATUS_TRIAL,
                AgencyToolSubscription::STATUS_CANCELLED,
                AgencyToolSubscription::STATUS_EXPIRED,
            ],
            'intervals' => [
                AgencyToolSubscription::INTERVAL_MONTHLY,
                AgencyToolSubscription::INTERVAL_YEARLY,
            ],
        ]);
    }

    public function save(): void
    {
        $teamId = currentTeam()->id;
        $data = $this->validate([
            'form.engagement_uuid' => ['nullable', 'string'],
            'form.vendor' => ['required', 'string', 'max:255'],
            'form.tool_name' => [
                'required',
                'string',
                'max:255',
            ],
            'form.amount' => ['required', 'numeric', 'min:0'],
            'form.currency' => ['required', 'string', 'size:3'],
            'form.interval' => ['required', Rule::in([
                AgencyToolSubscription::INTERVAL_MONTHLY,
                AgencyToolSubscription::INTERVAL_YEARLY,
            ])],
            'form.renews_at' => ['nullable', 'date'],
            'form.status' => ['required', Rule::in([
                AgencyToolSubscription::STATUS_ACTIVE,
                AgencyToolSubscription::STATUS_TRIAL,
                AgencyToolSubscription::STATUS_CANCELLED,
                AgencyToolSubscription::STATUS_EXPIRED,
            ])],
            'form.owner_notes' => ['nullable', 'string'],
        ])['form'];

        $engagement = filled($data['engagement_uuid'])
            ? AgencyEngagement::ownedByCurrentTeam()->where('uuid', $data['engagement_uuid'])->firstOrFail()
            : null;

        AgencyToolSubscription::query()->updateOrCreate(
            ['id' => $this->editingId, 'team_id' => $teamId],
            [
                'team_id' => $teamId,
                'agency_engagement_id' => $engagement?->id,
                'vendor' => $data['vendor'],
                'tool_name' => $data['tool_name'],
                'amount' => $data['amount'],
                'currency' => strtoupper($data['currency']),
                'interval' => $data['interval'],
                'renews_at' => filled($data['renews_at']) ? $data['renews_at'] : null,
                'status' => $data['status'],
                'owner_notes' => $data['owner_notes'] ?: null,
            ],
        );

        GlobalSearch::clearTeamCache($teamId);
        $this->resetForm();
        $this->dispatch('success', 'Agency tool subscription saved.');
    }

    public function edit(string $uuid): void
    {
        $subscription = AgencyToolSubscription::ownedByCurrentTeam()
            ->with('engagement')
            ->where('uuid', $uuid)
            ->firstOrFail();

        $this->editingId = $subscription->id;
        $this->form = [
            'engagement_uuid' => $subscription->engagement?->uuid ?: '',
            'vendor' => $subscription->vendor,
            'tool_name' => $subscription->tool_name,
            'amount' => (string) $subscription->amount,
            'currency' => $subscription->currency,
            'interval' => $subscription->interval,
            'renews_at' => $subscription->renews_at?->format('Y-m-d') ?: '',
            'status' => $subscription->status,
            'owner_notes' => $subscription->owner_notes,
        ];
    }

    public function delete(string $uuid): void
    {
        AgencyToolSubscription::ownedByCurrentTeam()->where('uuid', $uuid)->firstOrFail()->delete();
        GlobalSearch::clearTeamCache(currentTeam()->id);
        $this->resetForm();
        $this->dispatch('success', 'Agency tool subscription deleted.');
    }

    public function resetForm(): void
    {
        $this->editingId = null;
        $this->resetValidation();
        $this->form = [
            'engagement_uuid' => '',
            'vendor' => '',
            'tool_name' => '',
            'amount' => '0.00',
            'currency' => 'USD',
            'interval' => AgencyToolSubscription::INTERVAL_MONTHLY,
            'renews_at' => '',
            'status' => AgencyToolSubscription::STATUS_ACTIVE,
            'owner_notes' => '',
        ];
    }
}
