<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\BetaInvitation;
use App\Notifications\Concerns\DeliversToIntercom;
use App\Notifications\Concerns\DeliversToMicrosoftTeams;
use App\Notifications\Concerns\DeliversToPagerDuty;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The closed-beta invite email. Sent as an on-demand notification to an email
 * address (the invitee has no User account yet):
 *
 *   Notification::route('mail', $email)->notify(new BetaInvitationNotification($invite));
 */
class BetaInvitationNotification extends Notification implements ShouldQueue
{
    use DeliversToIntercom;
    use DeliversToMicrosoftTeams;
    use DeliversToPagerDuty;
    use Queueable;

    public function __construct(
        public BetaInvitation $invitation
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return array_merge(['mail'], $this->viaIntercom($notifiable), $this->viaMicrosoftTeams($notifiable), $this->viaPagerDuty($notifiable));
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('register', ['invite' => $this->invitation->token]);
        $app = config('app.name');
        $days = $this->invitation->expires_at->diffInDays(now()->subDay()) ?: BetaInvitation::expiryDays();

        return (new MailMessage)
            ->subject(__('You’re invited to the :app beta', ['app' => $app]))
            ->greeting(__('You’re in.'))
            ->line(__('You’ve been invited to the :app private beta.', ['app' => $app]))
            ->line(__('Deploy static sites, SSR apps and containers to the edge. Your organization starts with a :days-day :tier trial (card required, cancel any time before it ends).', [
                'days' => (int) config('subscription.standard.trial.days'),
                'tier' => (string) config('subscription.standard.tiers.'.config('subscription.standard.trial.tier').'.label'),
            ]))
            ->action(__('Create your account'), $url)
            ->line(__('This invite is tied to this email address and expires in :days days.', ['days' => (int) $days]))
            ->line(__('If you weren’t expecting this, you can ignore this email.'));
    }
}
