<?php

namespace App\Livewire\Team;

use App\Models\PolicyDecision;
use App\Models\TeamInvitation;
use App\Services\PolicyEngine;
use App\Services\TeamInvitationArtifactService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class InviteLink extends Component
{
    use AuthorizesRequests;

    public string $email;

    public string $role = 'member';

    protected $rules = [
        'email' => 'required|email',
        'role' => 'required|string',
    ];

    public function mount()
    {
        $this->email = isDev() ? 'test3@example.com' : '';
    }

    public function viaEmail()
    {
        $this->generateInviteLink(sendEmail: true);
    }

    public function viaLink()
    {
        $this->generateInviteLink(sendEmail: false);
    }

    private function generateInviteLink(bool $sendEmail = false)
    {
        try {
            $this->authorize('manageInvitations', currentTeam());
            $this->validate();

            $this->email = strtolower($this->email);

            $member_emails = currentTeam()->members()->get()->pluck('email');
            if ($member_emails->contains($this->email)) {
                return handleError(livewire: $this, customErrorMessage: "$this->email is already a member of ".currentTeam()->name.'.');
            }
            $uuid = new Cuid2(32);
            $link = route('auth.sl1.invitation', ['uuid' => (string) $uuid]);

            $invitation = TeamInvitation::whereTeamId(currentTeam()->id)->whereEmail($this->email)->first();
            if (! is_null($invitation)) {
                $invitationValid = $invitation->isValid();
                if ($invitationValid) {
                    return handleError(livewire: $this, customErrorMessage: "Pending invitation already exists for $this->email.");
                } else {
                    $invitation->delete();
                }
            }

            $decision = PolicyDecision::create(app(PolicyEngine::class)->evaluateTeamMemberInvite(
                issuer: auth()->user(),
                team: currentTeam(),
                requestedRole: $this->role,
                deliveryEmail: $this->email,
            ));
            if (! $decision->allows(TeamInvitationArtifactService::CAPABILITY_TEAM_MEMBER_INVITE)) {
                throw new \InvalidArgumentException('PolicyDecision does not allow team invitation issuance.');
            }

            $invitation = TeamInvitation::create([
                'team_id' => currentTeam()->id,
                'uuid' => $uuid,
                'email' => $this->email,
                'role' => data_get($decision->scope, 'role_scope'),
                'link' => $link,
                'via' => $sendEmail ? 'email' : 'link',
                'artifact_version' => TeamInvitationArtifactService::ARTIFACT_VERSION,
            ]);

            $intent = app(PolicyEngine::class)->stage('team.member.invite', currentTeam(), [
                'team_id' => currentTeam()->id,
                'team_name' => currentTeam()->name,
                'invitation_id' => $invitation->id,
                'invitation_uuid' => $invitation->uuid,
                'policy_decision_id' => $decision->id,
                'delivery_email' => $this->email,
                'delivery_email_hash' => hash('sha256', $this->email),
                'role_scope' => data_get($decision->scope, 'role_scope'),
                'send_email' => $sendEmail,
            ], currentTeam()->id);
            $invitation->forceFill(['pending_intent_id' => $intent->id])->save();

            $this->dispatch('success', 'Team invitation intent staged. Owner SL1 signature is required before the discovery pointer becomes active.');
            $this->dispatch('refreshInvitations');
        } catch (\Throwable $e) {
            $error_message = $e->getMessage();
            if ($e->getCode() === '23505') {
                $error_message = 'Invitation already sent.';
            }

            return handleError(error: $e, livewire: $this, customErrorMessage: $error_message);
        }
    }
}
