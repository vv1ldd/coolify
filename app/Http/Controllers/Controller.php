<?php

namespace App\Http\Controllers;

use App\Events\TestEvent;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Auth;

class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    public function realtime_test()
    {
        if (auth()->user()?->currentTeam()->id !== 0) {
            return redirect(RouteServiceProvider::HOME);
        }
        TestEvent::dispatch();

        return 'Look at your other tab.';
    }

    public function verify()
    {
        return redirect()->route('dashboard')->withErrors([
            'sl1' => 'Email verification is disabled. Identity is verified through SL1.',
        ]);
    }

    public function email_verify(Request $request)
    {
        abort(410, 'Email verification is constitutionally disabled. Use SL1 Identity.');
    }

    public function forgot_password(Request $request)
    {
        return redirect()->route('login')->withErrors([
            'sl1' => 'Password recovery is disabled. Use SL1 Identity.',
        ]);
    }

    public function link()
    {
        return redirect()->route('login')->withErrors([
            'sl1' => 'Magic links are disabled. Use SL1 Identity.',
        ]);
    }

    public function showInvitation()
    {
        $invitationUuid = request()->route('uuid');
        $invitation = TeamInvitation::whereUuid($invitationUuid)->firstOrFail();
        if ($invitation->artifact_version === 'team.invitation.v1') {
            return redirect()->route('auth.sl1.invitation', ['uuid' => $invitation->uuid]);
        }
        $user = User::whereEmail($invitation->email)->firstOrFail();

        if (Auth::id() !== $user->id) {
            abort(400, 'You are not allowed to accept this invitation.');
        }

        if (! $invitation->isValid()) {
            abort(400, 'Invitation expired.');
        }

        $alreadyMember = $user->teams()->where('team_id', $invitation->team->id)->exists();

        return view('invitation.accept', [
            'invitation' => $invitation,
            'team' => $invitation->team,
            'alreadyMember' => $alreadyMember,
        ]);
    }

    public function acceptInvitation()
    {
        $invitationUuid = request()->route('uuid');

        $invitation = TeamInvitation::whereUuid($invitationUuid)->firstOrFail();
        if ($invitation->artifact_version === 'team.invitation.v1') {
            return redirect()->route('auth.sl1.invitation', ['uuid' => $invitation->uuid]);
        }
        $user = User::whereEmail($invitation->email)->firstOrFail();

        if (Auth::id() !== $user->id) {
            abort(400, 'You are not allowed to accept this invitation.');
        }

        if (! $invitation->isValid()) {
            abort(400, 'Invitation expired.');
        }

        if ($user->teams()->where('team_id', $invitation->team->id)->exists()) {
            $invitation->delete();

            return redirect()->route('team.index');
        }
        $user->teams()->attach($invitation->team->id, ['role' => $invitation->role]);
        $invitation->delete();

        refreshSession($invitation->team);

        return redirect()->route('team.index');
    }
}
