<?php

namespace App\Livewire\Auth;

use Illuminate\Contracts\View\View;
use Livewire\Component;

class VerifyEmail extends Component
{
    public string $title = 'Verify email';

    public function mount(): mixed
    {
        if (auth()->user()->hasVerifiedEmail()) {
            return $this->redirect(route('dashboard'), navigate: true);
        }

        return null;
    }

    public function sendNotification(): void
    {
        if (auth()->user()->hasVerifiedEmail()) {
            $this->redirect(route('dashboard'), navigate: true);

            return;
        }

        try {
            auth()->user()->sendEmailVerificationNotification();
        } catch (\Throwable $e) {
            report($e);
            session()->flash('error', __('We couldn\'t send the verification email. Please try again in a few minutes.'));

            return;
        }

        session()->flash('status', 'verification-link-sent');
    }

    public function render(): View
    {
        return view('livewire.auth.verify-email')
            ->layout('layouts.guest-livewire', ['title' => $this->title]);
    }
}
