<?php

use App\Livewire\Team\InviteLink;
use App\Models\InfraLedger;
use App\Models\InstanceSettings;
use App\Models\PendingIntent;
use App\Models\PolicyDecision;
use App\Models\Sl1NotificationEnvelope;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\TeamInvitationArtifact;
use App\Models\User;
use App\Services\PolicyEngine;
use App\Services\Sl1NotificationEnvelopeService;
use App\Services\TeamInvitationArtifactService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::create(['id' => 0]));

    $this->team = Team::factory()->create();

    $this->owner = User::factory()->create();
    $this->admin = User::factory()->create();
    $this->member = User::factory()->create();

    $this->team->members()->attach($this->owner->id, ['role' => 'owner']);
    $this->team->members()->attach($this->admin->id, ['role' => 'admin']);
    $this->team->members()->attach($this->member->id, ['role' => 'member']);
});

function actAsTeamMember($test, User $user, Team $team): void
{
    $test->actingAs($user);
    session(['currentTeam' => $team]);
}

test('team invitation issuance creates bounded artifact without user or membership mutation', function () {
    actAsTeamMember($this, $this->owner, $this->team);

    Livewire::test(InviteLink::class)
        ->set('email', 'candidate@example.com')
        ->set('role', 'admin')
        ->call('viaLink')
        ->assertDispatched('success');

    $intent = PendingIntent::where('event_type', 'team.member.invite')->first();
    $invitation = TeamInvitation::first();

    expect($intent)->not->toBeNull()
        ->and($intent->status)->toBe(PendingIntent::STATUS_PENDING)
        ->and($invitation->pending_intent_id)->toBe($intent->id)
        ->and($invitation->team_invitation_artifact_id)->toBeNull()
        ->and(TeamInvitationArtifact::count())->toBe(0)
        ->and(Sl1NotificationEnvelope::count())->toBe(0);

    app(PolicyEngine::class)->addSignature($intent, 'DID:SL1|ENTITY:#owner', 'sl1-intent-approval');

    $artifact = TeamInvitationArtifact::first();
    $notification = Sl1NotificationEnvelope::first();
    $invitation->refresh();

    expect($artifact)->not->toBeNull()
        ->and($artifact->artifact_version)->toBe('team.invitation.v1')
        ->and($artifact->status)->toBe('issued')
        ->and($artifact->role_scope)->toBe('admin')
        ->and($artifact->delivery_email)->toBe('candidate@example.com')
        ->and($artifact->consumed_at)->toBeNull()
        ->and($invitation->artifact_version)->toBe('team.invitation.v1')
        ->and($invitation->team_invitation_artifact_id)->toBe($artifact->id)
        ->and($notification->envelope_version)->toBe('sl1.notification.v1')
        ->and($notification->authority_effect)->toBe('none')
        ->and($notification->non_authoritative)->toBeTrue()
        ->and($notification->consumes_artifact)->toBeFalse()
        ->and($notification->mutates_authority)->toBeFalse()
        ->and($notification->capabilities_granted)->toBe([]);

    expect(User::whereEmail('candidate@example.com')->exists())->toBeFalse()
        ->and($this->team->members()->where('users.email', 'candidate@example.com')->exists())->toBeFalse()
        ->and(str_contains($invitation->link, '/link?token='))->toBeFalse()
        ->and(str_contains($invitation->link, '/auth/sl1/invitation/'))->toBeTrue();

    expect(PolicyDecision::where('intent_type', 'team.member.invite')->count())->toBe(1);
    expect(InfraLedger::where('event_type', 'team.member.invite')->count())->toBe(1);
    expect(InfraLedger::where('event_type', 'team.member.invite.issued')->count())->toBe(1);
    expect(InfraLedger::where('event_type', 'sl1.notification.v1')->count())->toBe(1);
});

test('policy engine evaluates team invitation without persistence side effects', function () {
    $payload = app(PolicyEngine::class)->evaluateTeamMemberInvite(
        issuer: $this->owner,
        team: $this->team,
        requestedRole: 'admin',
        deliveryEmail: 'candidate@example.com',
    );

    expect($payload['decision'])->toBe('allow')
        ->and($payload['intent_type'])->toBe('team.member.invite')
        ->and(data_get($payload, 'scope.role_scope'))->toBe('admin')
        ->and(PolicyDecision::count())->toBe(0)
        ->and(TeamInvitationArtifact::count())->toBe(0)
        ->and(InfraLedger::count())->toBe(0);
});

test('team invitation artifact cannot be issued from denied policy decision', function () {
    $decision = PolicyDecision::create(app(PolicyEngine::class)->evaluateTeamMemberInvite(
        issuer: $this->admin,
        team: $this->team,
        requestedRole: 'owner',
        deliveryEmail: 'owner-candidate@example.com',
    ));

    expect(fn () => app(TeamInvitationArtifactService::class)->issueFromDecision($decision))
        ->toThrow(InvalidArgumentException::class, 'PolicyDecision does not allow team invitation issuance.');

    expect(TeamInvitationArtifact::count())->toBe(0);
});

test('legacy invitation acceptance cannot consume team invitation v1 artifact', function () {
    actAsTeamMember($this, $this->owner, $this->team);

    Livewire::test(InviteLink::class)
        ->set('email', 'candidate@example.com')
        ->set('role', 'member')
        ->call('viaLink');
    app(PolicyEngine::class)->addSignature(PendingIntent::firstOrFail(), 'DID:SL1|ENTITY:#owner', 'sl1-intent-approval');

    $joiningUser = User::factory()->create(['email' => 'candidate@example.com']);
    $joiningTeam = Team::factory()->create();
    $joiningTeam->members()->attach($joiningUser->id, ['role' => 'owner']);
    $this->actingAs($joiningUser);
    session(['currentTeam' => $joiningTeam]);

    $invitation = TeamInvitation::firstOrFail();

    $this->post(route('team.invitation.accept', ['uuid' => $invitation->uuid]))
        ->assertRedirect(route('auth.sl1.invitation', ['uuid' => $invitation->uuid]));

    expect($this->team->members()->where('users.id', $joiningUser->id)->exists())->toBeFalse()
        ->and(TeamInvitationArtifact::first()->status)->toBe('issued');
});

test('role drift on display invitation does not change frozen artifact scope', function () {
    actAsTeamMember($this, $this->owner, $this->team);

    Livewire::test(InviteLink::class)
        ->set('email', 'candidate@example.com')
        ->set('role', 'member')
        ->call('viaLink');
    app(PolicyEngine::class)->addSignature(PendingIntent::firstOrFail(), 'DID:SL1|ENTITY:#owner', 'sl1-intent-approval');

    $invitation = TeamInvitation::firstOrFail();
    $invitation->forceFill(['role' => 'owner'])->save();

    expect(TeamInvitationArtifact::first()->role_scope)->toBe('member');
});

test('notification envelope interaction cannot consume artifact or mutate membership', function () {
    actAsTeamMember($this, $this->owner, $this->team);

    Livewire::test(InviteLink::class)
        ->set('email', 'candidate@example.com')
        ->set('role', 'member')
        ->call('viaLink');
    app(PolicyEngine::class)->addSignature(PendingIntent::firstOrFail(), 'DID:SL1|ENTITY:#owner', 'sl1-intent-approval');

    $artifact = TeamInvitationArtifact::firstOrFail();
    $notification = Sl1NotificationEnvelope::firstOrFail();

    app(Sl1NotificationEnvelopeService::class)->markRead($notification);
    app(Sl1NotificationEnvelopeService::class)->dismiss($notification->refresh());
    $artifact->refresh();

    expect($notification->refresh()->status)->toBe('dismissed')
        ->and($artifact->status)->toBe('issued')
        ->and($artifact->consumed_at)->toBeNull()
        ->and($this->team->members()->where('users.email', 'candidate@example.com')->exists())->toBeFalse();
});

test('notification envelope cannot accrete authority fields', function () {
    actAsTeamMember($this, $this->owner, $this->team);

    Livewire::test(InviteLink::class)
        ->set('email', 'candidate@example.com')
        ->set('role', 'admin')
        ->call('viaLink');
    app(PolicyEngine::class)->addSignature(PendingIntent::firstOrFail(), 'DID:SL1|ENTITY:#owner', 'sl1-intent-approval');

    $notification = Sl1NotificationEnvelope::firstOrFail();

    expect(fn () => $notification->forceFill(['authority_effect' => 'grant'])->save())
        ->toThrow(LogicException::class, 'NotificationEnvelope cannot carry authority semantics.');
});
