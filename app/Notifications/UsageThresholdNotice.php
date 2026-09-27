<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** This period's usage passed $percent% of the org's usage soft limit (UsageAlerts). */
class UsageThresholdNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Organization $organization,
        public int $percent,
        public int $usedCents,
        public int $limitCents,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $money = static fn (int $cents): string => '$'.number_format($cents / 100, 2);

        return (new MailMessage)
            ->subject(__(':org has used :pct% of its usage alert', ['org' => $this->organization->name, 'pct' => $this->percent]))
            ->line(__('Usage beyond the plan for :org is :used so far this billing period — :pct% of its :limit usage alert.', [
                'org' => $this->organization->name,
                'used' => $money($this->usedCents),
                'pct' => $this->percent,
                'limit' => $money($this->limitCents),
            ]))
            ->line(__('Nothing is paused. Usage is billed on your next invoice, after the period ends. You can change the alert amount on the billing page.'))
            ->action(__('Billing'), route('subscription.show', $this->organization));
    }
}
