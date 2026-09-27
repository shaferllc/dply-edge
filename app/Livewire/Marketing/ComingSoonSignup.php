<?php

namespace App\Livewire\Marketing;

use App\Models\ComingSoonSignup as ComingSoonSignupRecord;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * Livewire exposes get<Name>Property() methods as $this-><name> in PHP and
 * Blade (the pre-#[Computed] convention). PHPStan cannot see that magic,
 * so the contract is stated here.
 *
 * @property-read string $successMessage
 */
class ComingSoonSignup extends Component
{
    public string $email = '';

    public bool $submitted = false;

    public bool $alreadySubscribed = false;

    public function submit(): void
    {
        $validated = $this->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
        ]);

        $signup = ComingSoonSignupRecord::subscribe($validated['email'], 'coming-soon');

        $this->email = '';
        $this->submitted = true;
        $this->alreadySubscribed = ! $signup->wasRecentlyCreated;
    }

    public function getSuccessMessageProperty(): string
    {
        if ($this->alreadySubscribed) {
            return __('You’re already on the list. We’ll be in touch as soon as it opens up.');
        }

        return __('You’re on the list, thanks! We’ll email you when dply edge opens up.');
    }

    public function render(): View
    {
        return view('livewire.marketing.coming-soon-signup', [
            'pageTitle' => __('dply early access'),
            'metaDescription' => __('Join the dply edge waitlist. Deploy Laravel, Rails and Node apps, with their databases and queue workers, straight from Git.'),
            'eyebrow' => __('Waitlist'),
            'headline' => __('Push a repo. Get the whole app running. Opening soon.'),
            'subheadline' => __('Leave your email and we’ll let you know when it’s ready: your Laravel, Rails or Node app, its database and queue workers, deployed from Git with nothing to configure first.'),
            'successMessage' => $this->successMessage,
        ])->layout('layouts.status-public', [
            'title' => Str::of(__('dply early access'))->append(' - ', config('app.name'))->value(),
        ]);
    }
}
