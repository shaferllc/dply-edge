<?php

namespace App\Livewire\Auth;

use App\Actions\Organizations\EnsureUserHasWorkspaceOrganization;
use App\Http\Controllers\Auth\OAuthController;
use App\Livewire\Forms\RegisterForm;
use App\Models\BetaInvitation;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Livewire\Attributes\Url;
use Livewire\Component;

class Register extends Component
{
    public RegisterForm $form;

    public string $title = 'Create account';

    /**
     * Beta invite token from the emailed link (?invite=…). A valid token lets
     * this email register while public signups are closed and flags the new org
     * as a beta participant.
     */
    #[Url(as: 'invite')]
    public ?string $invite = null;

    /**
     * Organization-invitation token from the Teams/Members invite email
     * (?org_invite=…). Distinct from the beta `invite` above: this one doesn't
     * grant beta, it means a real workspace is waiting for this address. It
     * likewise opens the door when public signups are closed — someone with a
     * live invitation is not a cold signup.
     */
    #[Url(as: 'org_invite')]
    public ?string $orgInvite = null;

    /**
     * Email is locked to the invited address when redeeming — preserves the
     * 1:1 invite→person→free-box attribution.
     */
    public bool $emailLocked = false;

    public function mount(): void
    {
        $invitation = $this->resolveInvitation();
        $orgInvitation = $this->resolveOrgInvitation();

        // An organization invitation is its own reason to let someone in: the
        // address was invited by name, so lock the form to it and skip the
        // closed-signups gate.
        if ($orgInvitation !== null) {
            $this->form->email = $orgInvitation->email;
            $this->emailLocked = true;
        }

        if ($invitation !== null) {
            // New-signups-only: an invited address that already has an account
            // bounces to login rather than silently granting beta.
            if (User::where('email', $invitation->email)->exists()) {
                session()->flash('status', __('You already have an account — please log in.'));
                $this->redirect(route('login'), navigate: true);

                return;
            }

            // Lock the form email to the invited address.
            $this->form->email = $invitation->email;
            $this->emailLocked = true;
        }

        if (! auth()->check()) {
            return;
        }

        $this->redirect(
            auth()->user()->hasVerifiedEmail()
                ? route('dashboard')
                : route('verification.notice'),
            navigate: true
        );
    }

    /**
     * The live organization invitation for the current token, or null when
     * absent/invalid/expired, or when that address already has an account
     * (they should sign in and accept, not register a second time).
     */
    private function resolveOrgInvitation(): ?OrganizationInvitation
    {
        if (blank($this->orgInvite)) {
            return null;
        }

        $invitation = OrganizationInvitation::where('token', $this->orgInvite)->first();

        if ($invitation === null || $invitation->isExpired()) {
            return null;
        }

        return User::where('email', $invitation->email)->exists() ? null : $invitation;
    }

    /**
     * The redeemable invite for the current token, or null when absent/invalid.
     */
    private function resolveInvitation(): ?BetaInvitation
    {
        if (blank($this->invite)) {
            return null;
        }

        $invitation = BetaInvitation::where('token', $this->invite)->first();

        return $invitation !== null && $invitation->isRedeemable() ? $invitation : null;
    }

    public function submit(): mixed
    {
        $invitation = $this->resolveInvitation();
        $orgInvitation = $this->resolveOrgInvitation();

        // Re-pin the email to the invite server-side — never trust a client that
        // edited the locked field.
        if ($orgInvitation !== null) {
            $this->form->email = $orgInvitation->email;
        }

        if ($invitation !== null) {
            $this->form->email = $invitation->email;
        }

        $this->form->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = User::create([
            'name' => $this->form->name,
            'email' => $this->form->email,
            'password' => Hash::make($this->form->password),
        ]);
        $organization = EnsureUserHasWorkspaceOrganization::run($user);

        // Redeem the invite: flag the new org beta + apply the beta feature
        // bundle (see BetaInvitation::redeem).
        $invitation?->redeem($user, $organization);

        // The account exists by now — a mail outage must not strand the user on an
        // error page. They can resend from the verify-email screen.
        try {
            event(new Registered($user));
        } catch (\Throwable $e) {
            report($e);
        }
        Auth::login($user);
        session()->regenerate();
        session(['current_organization_id' => $organization->id]);

        // Straight back to the invitation they came from, so the account they
        // just made lands them in the workspace that invited them rather than
        // in a fresh empty one.
        $target = match (true) {
            $orgInvitation !== null => route('invitations.accept', ['token' => $orgInvitation->token]),
            $user->hasVerifiedEmail() => route('dashboard'),
            default => route('verification.notice'),
        };

        return $this->redirect($target, navigate: true);
    }

    public function render(): View
    {
        return view('livewire.auth.register', [
            'oauthProviders' => OAuthController::getEnabledProviders(),
        ])->layout('layouts.guest-livewire', ['title' => $this->title]);
    }
}
