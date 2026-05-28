<?php

namespace App\Livewire\Team;

use App\Models\PolicyDecision;
use App\Models\TeamInvitation;
use App\Services\PolicyEngine;
use App\Services\Sl1NotificationEnvelopeService;
use App\Services\TeamInvitationArtifactService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Notifications\Messages\MailMessage;
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
            $link = url('/').config('constants.invitation.link.base_url').$uuid;

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
            $artifact = app(TeamInvitationArtifactService::class)->issueFromDecision($decision);

            $invitation = TeamInvitation::create([
                'team_id' => currentTeam()->id,
                'uuid' => $uuid,
                'email' => $this->email,
                'role' => $artifact->role_scope,
                'link' => $link,
                'via' => $sendEmail ? 'email' : 'link',
                'artifact_version' => $artifact->artifact_version,
                'team_invitation_artifact_id' => $artifact->id,
            ]);
            app(Sl1NotificationEnvelopeService::class)->publishTeamInvitationDiscovery(
                artifact: $artifact,
                deliveryChannels: $sendEmail ? ['email.discovery'] : ['manual.discovery'],
                actor: auth()->user(),
            );
            if ($sendEmail) {
                $mail = new MailMessage;
                $mail->view('emails.invitation-link', [
                    'team' => currentTeam()->name,
                    'discovery_link' => route('login'),
                ]);
                $mail->subject('SL1 discovery notice for '.currentTeam()->name.' on '.config('app.name').'.');
                send_user_an_email($mail, $this->email);
                $this->dispatch('success', 'Discovery notice sent via email.');
                $this->dispatch('refreshInvitations');

                return;
            } else {
                $this->dispatch('success', 'Invitation artifact and discovery pointer generated.');
                $this->dispatch('refreshInvitations');
            }
        } catch (\Throwable $e) {
            $error_message = $e->getMessage();
            if ($e->getCode() === '23505') {
                $error_message = 'Invitation already sent.';
            }

            return handleError(error: $e, livewire: $this, customErrorMessage: $error_message);
        }
    }
}
