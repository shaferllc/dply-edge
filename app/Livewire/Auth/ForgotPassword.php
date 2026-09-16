<?php

namespace App\Livewire\Auth;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Password;
use Livewire\Component;

class ForgotPassword extends Component
{
    public string $email = '';

    public string $title = 'Forgot password';

    public function submit(): void
    {
        $this->validate([
            'email' => ['required', 'email'],
        ]);

        try {
            $status = Password::sendResetLink(['email' => $this->email]);
        } catch (\Throwable $e) {
            report($e);
            $this->addError('email', __('We couldn\'t send the reset email. Please try again in a few minutes.'));

            return;
        }

        if ($status == Password::RESET_LINK_SENT) {
            session()->flash('status', __($status));
        } else {
            $this->addError('email', __($status));
        }
    }

    public function render(): View
    {
        return view('livewire.auth.forgot-password')
            ->layout('layouts.guest-livewire', ['title' => $this->title]);
    }
}
