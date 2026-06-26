<?php

namespace App\Services;

use App\Models\AgencyClient;
use App\Models\AgencyDomainAsset;
use App\Models\AgencyEngagement;
use App\Models\AgencyToolSubscription;
use Illuminate\Support\Collection;

class AgencyOperationsService
{
    public function summaryForTeam(int $teamId): array
    {
        $openStatuses = [
            AgencyEngagement::STATUS_PENDING,
            AgencyEngagement::STATUS_DEVELOPMENT,
            AgencyEngagement::STATUS_TESTING,
            AgencyEngagement::STATUS_PRODUCTION,
            AgencyEngagement::STATUS_PAUSED,
        ];

        return [
            'clients_count' => AgencyClient::query()->where('team_id', $teamId)->count(),
            'open_engagements_count' => AgencyEngagement::query()
                ->where('team_id', $teamId)
                ->whereIn('status', $openStatuses)
                ->count(),
            'domains_count' => AgencyDomainAsset::query()->where('team_id', $teamId)->count(),
            'active_subscriptions_count' => AgencyToolSubscription::query()
                ->where('team_id', $teamId)
                ->whereIn('status', [AgencyToolSubscription::STATUS_ACTIVE, AgencyToolSubscription::STATUS_TRIAL])
                ->count(),
            'engagements_by_status' => $this->engagementsByStatus($teamId),
            'domain_alerts' => $this->domainAlerts($teamId),
            'subscription_alerts' => $this->subscriptionAlerts($teamId),
            'client_responsibility' => $this->clientResponsibility($teamId),
        ];
    }

    public function engagementsByStatus(int $teamId): Collection
    {
        return AgencyEngagement::query()
            ->where('team_id', $teamId)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');
    }

    public function domainAlerts(int $teamId): Collection
    {
        return AgencyDomainAsset::query()
            ->with('engagement.client')
            ->where('team_id', $teamId)
            ->where(function ($query) {
                $query
                    ->whereNull('agency_engagement_id')
                    ->orWhereNull('expires_at')
                    ->orWhere('expires_at', '<=', now()->addDays(30)->toDateString());
            })
            ->orderByRaw('expires_at IS NULL')
            ->orderBy('expires_at')
            ->limit(8)
            ->get();
    }

    public function subscriptionAlerts(int $teamId): Collection
    {
        return AgencyToolSubscription::query()
            ->with('engagement.client')
            ->where('team_id', $teamId)
            ->where(function ($query) {
                $query
                    ->whereNull('agency_engagement_id')
                    ->orWhereNull('renews_at')
                    ->orWhere('renews_at', '<=', now()->addDays(30)->toDateString());
            })
            ->orderByRaw('renews_at IS NULL')
            ->orderBy('renews_at')
            ->limit(8)
            ->get();
    }

    public function clientResponsibility(int $teamId): Collection
    {
        return AgencyClient::query()
            ->where('team_id', $teamId)
            ->withCount([
                'engagements',
                'engagements as open_engagements_count' => fn ($query) => $query->whereNot('status', AgencyEngagement::STATUS_ARCHIVED),
            ])
            ->orderByDesc('open_engagements_count')
            ->orderByRaw('LOWER(name)')
            ->limit(8)
            ->get();
    }
}
