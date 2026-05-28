<?php

namespace App\Livewire\Profile;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Index extends Component
{
    public int $userId;

    public string $email;

    public string $current_password;

    public string $new_password;

    public string $new_password_confirmation;

    #[Validate('required')]
    public string $name;

    public string $new_email = '';

    public string $email_verification_code = '';

    public bool $show_email_change = false;

    public bool $show_verification = false;

    public function mount()
    {
        $this->userId = Auth::id();
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;

        $this->show_email_change = false;
        $this->show_verification = false;
    }

    public function submit()
    {
        try {
            $this->validate([
                'name' => 'required',
            ]);
            Auth::user()->update([
                'name' => $this->name,
            ]);

            $this->dispatch('success', 'Profile updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function requestEmailChange()
    {
        $this->disableEmailAuthorityMutation();
    }

    public function verifyEmailChange()
    {
        $this->disableEmailAuthorityMutation();
    }

    public function resendVerificationCode()
    {
        $this->disableEmailAuthorityMutation();
    }

    public function cancelEmailChange()
    {
        Auth::user()->clearEmailChangeRequest();
        $this->new_email = '';
        $this->email_verification_code = '';
        $this->show_email_change = false;
        $this->show_verification = false;

        $this->dispatch('success', 'Email change request cancelled.');
    }

    public function showEmailChangeForm()
    {
        $this->disableEmailAuthorityMutation();
    }

    public function resetPassword()
    {
        $this->dispatch('error', 'Password changes are disabled. Use SL1 Identity.');
    }

    private function disableEmailAuthorityMutation(): void
    {
        Auth::user()->clearEmailChangeRequest();
        $this->new_email = '';
        $this->email_verification_code = '';
        $this->show_email_change = false;
        $this->show_verification = false;

        $this->dispatch('error', 'Email is an SL1 identity projection and cannot mutate identity authority.');
    }

    public function render()
    {
        return view('livewire.profile.index');
    }
}
