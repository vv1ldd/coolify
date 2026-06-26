<?php

use App\Livewire\Agency\Clients as AgencyClients;
use App\Livewire\Agency\Domains as AgencyDomains;
use App\Livewire\Agency\Engagements as AgencyEngagements;
use App\Livewire\Agency\Subscriptions as AgencySubscriptions;
use App\Livewire\GlobalSearch;
use App\Models\Application;
use App\Models\AgencyClient;
use App\Models\AgencyDomainAsset;
use App\Models\AgencyEngagement;
use App\Models\AgencyToolSubscription;
use App\Models\DnsZone;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use App\Services\AgencyOperationsService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::create(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

test('agency pages render only current team commitments', function () {
    $visibleClient = AgencyClient::create([
        'team_id' => $this->team->id,
        'name' => 'Visible Client',
        'contact_email' => 'visible@example.com',
    ]);
    AgencyEngagement::create([
        'team_id' => $this->team->id,
        'agency_client_id' => $visibleClient->id,
        'name' => 'Visible Storefront',
        'status' => AgencyEngagement::STATUS_DEVELOPMENT,
    ]);

    $otherTeam = Team::factory()->create();
    AgencyClient::create([
        'team_id' => $otherTeam->id,
        'name' => 'Hidden Client',
    ]);

    $this->get(route('agency.index'))
        ->assertOk()
        ->assertSee('Agency Control Center')
        ->assertSee('Commitment authority')
        ->assertSee('Visible Client')
        ->assertDontSee('Hidden Client');

    $this->get(route('agency.clients'))
        ->assertOk()
        ->assertSee('Visible Client')
        ->assertDontSee('Hidden Client');
});

test('clients and engagements can be created and updated through livewire', function () {
    Livewire::test(AgencyClients::class)
        ->set('form.name', 'Acme Agency Customer')
        ->set('form.contact_email', 'owner@acme.test')
        ->call('save')
        ->assertHasNoErrors();

    $client = AgencyClient::where('team_id', $this->team->id)->where('name', 'Acme Agency Customer')->firstOrFail();
    $project = Project::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'Acme Infra Project',
    ]);

    Livewire::test(AgencyEngagements::class)
        ->set('form.client_uuid', $client->uuid)
        ->set('form.project_uuid', $project->uuid)
        ->set('form.name', 'Acme Storefront Launch')
        ->set('form.status', AgencyEngagement::STATUS_DEVELOPMENT)
        ->set('form.priority', AgencyEngagement::PRIORITY_HIGH)
        ->call('save')
        ->assertHasNoErrors();

    $engagement = AgencyEngagement::where('team_id', $this->team->id)->where('name', 'Acme Storefront Launch')->firstOrFail();
    expect($engagement->agency_client_id)->toBe($client->id)
        ->and($engagement->project_id)->toBe($project->id)
        ->and($engagement->status)->toBe(AgencyEngagement::STATUS_DEVELOPMENT);

    Livewire::test(AgencyEngagements::class)
        ->call('edit', $engagement->uuid)
        ->set('form.status', AgencyEngagement::STATUS_TESTING)
        ->call('save')
        ->assertHasNoErrors();

    expect($engagement->fresh()->status)->toBe(AgencyEngagement::STATUS_TESTING);
});

test('domain assets and tool subscriptions support optional infrastructure links', function () {
    $client = AgencyClient::create([
        'team_id' => $this->team->id,
        'name' => 'Operations Client',
    ]);
    $engagement = AgencyEngagement::create([
        'team_id' => $this->team->id,
        'agency_client_id' => $client->id,
        'name' => 'Operations Retainer',
    ]);
    $zone = DnsZone::create([
        'team_id' => $this->team->id,
        'provider' => 'cloudflare',
        'name' => 'operations.example',
        'provider_zone_id' => 'zone-agency',
        'api_token' => 'token',
    ]);

    Livewire::test(AgencyDomains::class)
        ->set('form.domain', 'ops.example.com')
        ->set('form.engagement_uuid', $engagement->uuid)
        ->set('form.dns_zone_uuid', $zone->uuid)
        ->set('form.registrar', 'Namecheap')
        ->set('form.expires_at', now()->addDays(10)->toDateString())
        ->call('save')
        ->assertHasNoErrors();

    Livewire::test(AgencySubscriptions::class)
        ->set('form.vendor', 'GitHub')
        ->set('form.tool_name', 'Team Plan')
        ->set('form.engagement_uuid', $engagement->uuid)
        ->set('form.amount', '100.00')
        ->set('form.renews_at', now()->addDays(5)->toDateString())
        ->call('save')
        ->assertHasNoErrors();

    $domain = AgencyDomainAsset::where('team_id', $this->team->id)->where('domain', 'ops.example.com')->firstOrFail();
    $subscription = AgencyToolSubscription::where('team_id', $this->team->id)->where('vendor', 'GitHub')->firstOrFail();

    expect($domain->agency_engagement_id)->toBe($engagement->id)
        ->and($domain->dns_zone_id)->toBe($zone->id)
        ->and($subscription->agency_engagement_id)->toBe($engagement->id)
        ->and((string) $subscription->amount)->toBe('100.00');
});

test('agency operations summary reports responsibility alerts', function () {
    $client = AgencyClient::create([
        'team_id' => $this->team->id,
        'name' => 'Summary Client',
    ]);
    $engagement = AgencyEngagement::create([
        'team_id' => $this->team->id,
        'agency_client_id' => $client->id,
        'name' => 'Summary Engagement',
        'status' => AgencyEngagement::STATUS_PRODUCTION,
    ]);
    AgencyDomainAsset::create([
        'team_id' => $this->team->id,
        'agency_engagement_id' => $engagement->id,
        'domain' => 'summary.example.com',
        'expires_at' => now()->addDays(3),
    ]);
    AgencyToolSubscription::create([
        'team_id' => $this->team->id,
        'agency_engagement_id' => $engagement->id,
        'vendor' => 'Figma',
        'tool_name' => 'Design',
        'amount' => 24,
        'renews_at' => now()->addDays(4),
    ]);

    $summary = app(AgencyOperationsService::class)->summaryForTeam($this->team->id);

    expect($summary['clients_count'])->toBe(1)
        ->and($summary['open_engagements_count'])->toBe(1)
        ->and($summary['domains_count'])->toBe(1)
        ->and($summary['active_subscriptions_count'])->toBe(1)
        ->and($summary['domain_alerts']->first()->domain)->toBe('summary.example.com')
        ->and($summary['subscription_alerts']->first()->vendor)->toBe('Figma');
});

test('global search includes agency records scoped to current team', function () {
    AgencyClient::create([
        'team_id' => $this->team->id,
        'name' => 'Searchable Agency Client',
    ]);
    AgencyDomainAsset::create([
        'team_id' => $this->team->id,
        'domain' => 'searchable-agency.example.com',
    ]);

    $otherTeam = Team::factory()->create();
    AgencyClient::create([
        'team_id' => $otherTeam->id,
        'name' => 'Hidden Search Client',
    ]);

    $component = Livewire::test(GlobalSearch::class)
        ->call('openSearchModal')
        ->set('searchQuery', 'Searchable');

    $resultNames = collect($component->get('searchResults'))->pluck('name');

    expect($resultNames)->toContain('Searchable Agency Client')
        ->and($resultNames)->toContain('searchable-agency.example.com')
        ->and($resultNames)->not()->toContain('Hidden Search Client');
});

test('agency commitments remain readable and editable after infrastructure is removed', function () {
    $project = Project::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'Disposable Infra Project',
    ]);
    $environment = $project->environments()->first();
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'name' => 'Disposable App',
    ]);
    $service = Service::factory()->create([
        'environment_id' => $environment->id,
        'name' => 'Disposable Service',
    ]);
    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'Disposable Server',
    ]);
    $zone = DnsZone::create([
        'team_id' => $this->team->id,
        'provider' => 'cloudflare',
        'name' => 'disposable.example',
        'provider_zone_id' => 'zone-disposable',
        'api_token' => 'token',
    ]);

    $client = AgencyClient::create([
        'team_id' => $this->team->id,
        'name' => 'Infra Independent Client',
    ]);
    $engagement = AgencyEngagement::create([
        'team_id' => $this->team->id,
        'agency_client_id' => $client->id,
        'project_id' => $project->id,
        'name' => 'Infra Independent Engagement',
        'status' => AgencyEngagement::STATUS_DEVELOPMENT,
    ]);
    AgencyDomainAsset::create([
        'team_id' => $this->team->id,
        'agency_engagement_id' => $engagement->id,
        'dns_zone_id' => $zone->id,
        'domain' => 'infra-independent.example.com',
    ]);
    AgencyToolSubscription::create([
        'team_id' => $this->team->id,
        'agency_engagement_id' => $engagement->id,
        'vendor' => 'Linear',
        'tool_name' => 'Issues',
        'amount' => 20,
    ]);

    $application->delete();
    $service->delete();
    $server->delete();
    $zone->delete();
    $project->delete();

    expect(AgencyEngagement::findOrFail($engagement->id)->project_id)->toBeNull()
        ->and(AgencyDomainAsset::where('domain', 'infra-independent.example.com')->firstOrFail()->dns_zone_id)->toBeNull();

    $this->get(route('agency.index'))
        ->assertOk()
        ->assertSee('Infra Independent Client')
        ->assertSee('Infra Independent Engagement')
        ->assertSee('infra-independent.example.com')
        ->assertSee('Linear');

    Livewire::test(AgencyEngagements::class)
        ->call('edit', $engagement->uuid)
        ->set('form.status', AgencyEngagement::STATUS_PRODUCTION)
        ->call('save')
        ->assertHasNoErrors();

    Livewire::test(AgencyDomains::class)
        ->call('edit', AgencyDomainAsset::where('domain', 'infra-independent.example.com')->firstOrFail()->uuid)
        ->set('form.status', AgencyDomainAsset::STATUS_EXPIRING)
        ->call('save')
        ->assertHasNoErrors();

    Livewire::test(AgencySubscriptions::class)
        ->call('edit', AgencyToolSubscription::where('vendor', 'Linear')->firstOrFail()->uuid)
        ->set('form.status', AgencyToolSubscription::STATUS_TRIAL)
        ->call('save')
        ->assertHasNoErrors();
});

test('agency summary and edit actions are isolated to current team', function () {
    $visibleClient = AgencyClient::create([
        'team_id' => $this->team->id,
        'name' => 'Visible Summary Client',
    ]);
    AgencyEngagement::create([
        'team_id' => $this->team->id,
        'agency_client_id' => $visibleClient->id,
        'name' => 'Visible Summary Engagement',
    ]);

    $otherTeam = Team::factory()->create();
    $hiddenClient = AgencyClient::create([
        'team_id' => $otherTeam->id,
        'name' => 'Hidden Summary Client',
    ]);
    $hiddenEngagement = AgencyEngagement::create([
        'team_id' => $otherTeam->id,
        'agency_client_id' => $hiddenClient->id,
        'name' => 'Hidden Summary Engagement',
    ]);
    AgencyDomainAsset::create([
        'team_id' => $otherTeam->id,
        'agency_engagement_id' => $hiddenEngagement->id,
        'domain' => 'hidden-summary.example.com',
    ]);
    AgencyToolSubscription::create([
        'team_id' => $otherTeam->id,
        'agency_engagement_id' => $hiddenEngagement->id,
        'vendor' => 'Hidden Vendor',
        'tool_name' => 'Hidden Tool',
    ]);

    $summary = app(AgencyOperationsService::class)->summaryForTeam($this->team->id);

    expect($summary['clients_count'])->toBe(1)
        ->and($summary['open_engagements_count'])->toBe(1)
        ->and($summary['domains_count'])->toBe(0)
        ->and($summary['active_subscriptions_count'])->toBe(0);

    $this->get(route('agency.index'))
        ->assertOk()
        ->assertSee('Visible Summary Client')
        ->assertDontSee('Hidden Summary Client')
        ->assertDontSee('hidden-summary.example.com')
        ->assertDontSee('Hidden Vendor');

    expect(fn () => Livewire::test(AgencyEngagements::class)->call('edit', $hiddenEngagement->uuid))
        ->toThrow(ModelNotFoundException::class);
});

test('engagement remains the center for domains and tool subscriptions', function () {
    $client = AgencyClient::create([
        'team_id' => $this->team->id,
        'name' => 'Centered Client',
    ]);
    $firstEngagement = AgencyEngagement::create([
        'team_id' => $this->team->id,
        'agency_client_id' => $client->id,
        'name' => 'First Responsibility',
    ]);
    $secondEngagement = AgencyEngagement::create([
        'team_id' => $this->team->id,
        'agency_client_id' => $client->id,
        'name' => 'Second Responsibility',
    ]);
    AgencyDomainAsset::create([
        'team_id' => $this->team->id,
        'agency_engagement_id' => $firstEngagement->id,
        'domain' => 'first-centered.example.com',
    ]);
    AgencyDomainAsset::create([
        'team_id' => $this->team->id,
        'agency_engagement_id' => $firstEngagement->id,
        'domain' => 'second-centered.example.com',
    ]);
    AgencyToolSubscription::create([
        'team_id' => $this->team->id,
        'agency_engagement_id' => $firstEngagement->id,
        'vendor' => 'Notion',
        'tool_name' => 'Workspace',
    ]);
    AgencyToolSubscription::create([
        'team_id' => $this->team->id,
        'agency_engagement_id' => $secondEngagement->id,
        'vendor' => 'Vercel',
        'tool_name' => 'Pro',
    ]);

    $firstEngagement->load(['client.engagements', 'domains', 'toolSubscriptions']);
    $secondEngagement->load(['domains', 'toolSubscriptions']);

    expect($firstEngagement->client->engagements)->toHaveCount(2)
        ->and($firstEngagement->domains)->toHaveCount(2)
        ->and($firstEngagement->toolSubscriptions)->toHaveCount(1)
        ->and($secondEngagement->domains)->toHaveCount(0)
        ->and($secondEngagement->toolSubscriptions)->toHaveCount(1);
});
