<?php

namespace App\Livewire\Legal;

use Illuminate\Contracts\View\View;
use Livewire\Component;

/** Accept the current Terms, Privacy Policy and AUP (EnsureCurrentTermsAccepted sends users here). */
class AcceptTerms extends Component
{
    public bool $agree = false;

    public function accept(): mixed
    {
        $this->validate(['agree' => ['accepted']], ['agree.accepted' => __('Tick the box to continue.')]);
        $user = auth()->user();
        abort_if($user === null, 403);
        $user->acceptTerms();

        return $this->redirectIntended(route('dashboard'));
    }

    public function render(): View
    {
        return view('livewire.legal.accept-terms', [
            'updated' => auth()->user()?->terms_version !== null,
        ])->layout('layouts.guest-livewire', ['title' => __('Terms of Service')]);
    }
}
