<?php

namespace App\Livewire;

use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Livewire\Component;

class ForcePasswordReset extends Component
{
    use WithRateLimiting;

    public string $email;

    public string $password;

    public string $password_confirmation;

    public function rules(): array
    {
        return [];
    }

    public function mount()
    {
        if (auth()->user()->force_password_reset === false) {
            return redirect()->route('dashboard');
        }
        auth()->user()->forceFill(['force_password_reset' => false])->saveQuietly();

        return redirect()->route('dashboard');
    }

    public function render()
    {
        return view('livewire.force-password-reset')->layout('layouts.simple');
    }

    public function submit()
    {
        auth()->user()->forceFill(['force_password_reset' => false])->saveQuietly();

        return redirect()->route('dashboard');
    }
}
